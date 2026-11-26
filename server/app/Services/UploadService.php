<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Str;
use App\Core\ValidationException;

/**
 * Resumable chunked uploads.
 *  1. init      -> creates an upload session (idempotent: the same file resumes the same session)
 *  2. chunk     -> each chunk is size-checked, SHA-256 verified (X-Chunk-Sha256), stored atomically, idempotent
 *  3. status    -> lists received chunks so a client can resume after a disconnect or an app restart
 *  4. complete  -> assembles the file while hashing it (SHA-256), compares with the client hash, moves it into place.
 *                  Large files are assembled by the queue worker (bin/worker.php) and the status becomes "processing".
 */
final class UploadService
{
    private const IMAGES = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public const PURPOSES = [
        'game_file' => ['perm' => 'files.upload', 'game' => true],
        'cover' => ['perm' => 'media.upload', 'game' => true, 'ext' => self::IMAGES, 'max_mb' => 30],
        'banner' => ['perm' => 'media.upload', 'game' => true, 'ext' => self::IMAGES, 'max_mb' => 30],
        'screenshot' => ['perm' => 'media.upload', 'game' => true, 'ext' => self::IMAGES, 'max_mb' => 30],
        'trailer' => ['perm' => 'media.upload', 'game' => true, 'ext' => ['mp4', 'webm'], 'max_mb' => 4096],
        'platform_logo' => ['perm' => 'platforms.manage', 'ext' => self::IMAGES, 'max_mb' => 15],
        'platform_image' => ['perm' => 'platforms.manage', 'ext' => self::IMAGES, 'max_mb' => 30],
        'avatar' => ['perm' => null, 'ext' => self::IMAGES, 'max_mb' => 8],
        'music' => ['perm' => 'settings.manage', 'ext' => ['mp3', 'ogg'], 'max_mb' => 60],
        'backup' => ['perm' => 'backups.restore', 'ext' => ['gz', 'sql', 'zip'], 'max_mb' => 102400],
        'import' => ['perm' => 'games.import', 'ext' => ['csv', 'json'], 'max_mb' => 30],
    ];

    /** Never accepted, whatever the configuration says. */
    public const BLOCKED = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'cgi', 'pl', 'py', 'sh', 'bash',
        'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'js', 'mjs', 'html', 'htm', 'shtml', 'xhtml', 'svg', 'htaccess', 'htpasswd', 'ini',
        'asp', 'aspx', 'jsp', 'jar', 'vbs', 'ps1', 'scr', 'hta', 'lnk'];

    public static function chunksRoot(): string
    {
        return STORAGE_PATH . '/chunks';
    }

    public static function chunkSize(): int
    {
        $wanted = max(1, (int) Settings::get('uploads.chunk_size_mb', 5)) * 1024 * 1024;
        $limit = (int) (Str::iniBytes((string) ini_get('post_max_size')) * 0.9);
        return max(256 * 1024, min($wanted, $limit, 64 * 1024 * 1024));
    }

    public static function allowedGameExtensions(): array
    {
        $list = array_filter(array_map(static fn ($e) => strtolower(trim($e, " .\t")), explode(',', (string) Settings::get('uploads.allowed_extensions'))));
        return array_values(array_diff($list, self::BLOCKED));
    }

    private static function maxBytes(string $purpose): int
    {
        if ($purpose === 'game_file') {
            return (int) (max(1, (float) Settings::get('uploads.max_file_gb', 200)) * 1024 ** 3);
        }
        return self::PURPOSES[$purpose]['max_mb'] * 1024 * 1024;
    }

    public static function init(array $input): array
    {
        $user = Auth::user() ?? throw new HttpException(401);
        $purpose = (string) ($input['purpose'] ?? 'game_file');
        $spec = self::PURPOSES[$purpose] ?? throw ValidationException::single('purpose', 'نوع الرفع غير معروف');
        if ($spec['perm'] !== null && !Auth::can($spec['perm'])) {
            throw new HttpException(403, 'ليست لديك صلاحية لهذا النوع من الرفع');
        }
        $name = Str::safeFilename((string) ($input['filename'] ?? $input['file_name'] ?? ''));
        $size = filter_var($input['size'] ?? null, FILTER_VALIDATE_INT);
        if ($name === 'file' && empty($input['filename'])) {
            throw ValidationException::single('filename', 'اسم الملف مطلوب');
        }
        if ($size === false || $size < 1) {
            throw ValidationException::single('size', 'حجم الملف غير صحيح');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || in_array($ext, self::BLOCKED, true) || preg_match('/\.(php\d?|phtml|phar|cgi|pl|py|sh|exe|htaccess)(\.|$)/i', $name)) {
            throw ValidationException::single('filename', 'نوع الملف غير مسموح لأسباب أمنية');
        }
        $allowed = $purpose === 'game_file' ? self::allowedGameExtensions() : $spec['ext'];
        if (!in_array($ext, $allowed, true)) {
            throw ValidationException::single('filename', 'الامتداد .' . $ext . ' غير مسموح. المسموح: ' . implode('، ', $allowed));
        }
        $max = self::maxBytes($purpose);
        if ($size > $max) {
            throw new HttpException(413, 'حجم الملف يتجاوز الحد المسموح (' . human_bytes($max) . ')');
        }
        $disk = (string) ($input['disk'] ?? Settings::get('storage.default_disk', 'local'));
        if (!isset(FileService::disks()[$disk])) {
            throw ValidationException::single('disk', 'قرص التخزين غير معروف');
        }
        $free = @disk_free_space(self::chunksRoot());
        if ($free !== false && $free < $size + 256 * 1024 * 1024) {
            throw new HttpException(507, 'لا توجد مساحة كافية على الخادم لاستقبال هذا الملف (المتاح ' . human_bytes($free) . ')');
        }

        $gameId = null;
        $meta = [];
        if (!empty($spec['game'])) {
            $gameId = (int) ($input['game_id'] ?? 0);
            if (!$gameId || !DB::value('SELECT 1 FROM games WHERE id = ?', [$gameId])) {
                throw ValidationException::single('game_id', 'اختر اللعبة التي يتبعها الملف');
            }
        }
        if ($purpose === 'game_file') {
            $meta = [
                'file_type' => in_array($input['file_type'] ?? '', FileService::TYPES, true) ? $input['file_type'] : 'game',
                'access_level' => in_array($input['access_level'] ?? '', FileService::ACCESS, true) ? $input['access_level'] : 'registered',
                'label' => isset($input['label']) ? mb_substr(trim((string) $input['label']), 0, 190) : null,
                'region' => in_array($input['region'] ?? '', GameService::REGIONS, true) ? $input['region'] : null,
                'version_label' => isset($input['version_label']) ? mb_substr(trim((string) $input['version_label']), 0, 32) : null,
                'version_id' => null,
            ];
            if (!empty($input['version_id'])) {
                if (!DB::value('SELECT 1 FROM game_versions WHERE id = ? AND game_id = ?', [(int) $input['version_id'], $gameId])) {
                    throw ValidationException::single('version_id', 'الإصدار لا يتبع هذه اللعبة');
                }
                $meta['version_id'] = (int) $input['version_id'];
            }
        } elseif ($purpose === 'platform_logo' || $purpose === 'platform_image') {
            $pid = (int) ($input['platform_id'] ?? 0);
            if (!DB::value('SELECT 1 FROM platforms WHERE id = ?', [$pid])) {
                throw ValidationException::single('platform_id', 'الجهاز غير موجود');
            }
            $meta = ['platform_id' => $pid];
        } elseif ($purpose === 'screenshot') {
            $meta = ['caption' => isset($input['caption']) ? mb_substr((string) $input['caption'], 0, 190) : null];
        }
        $expected = isset($input['sha256']) && is_string($input['sha256']) ? strtolower(trim($input['sha256'])) : null;
        if ($expected !== null && $expected !== '' && !preg_match('/^[a-f0-9]{64}$/', $expected)) {
            throw ValidationException::single('sha256', 'قيمة SHA-256 غير صحيحة');
        }

        // Resume: the same user re-initialising the same file gets the same session back.
        $existing = DB::one(
            "SELECT uuid FROM uploads WHERE user_id = ? AND purpose = ? AND original_name = ? AND size_bytes = ? AND status = 'uploading'
               AND (game_id <=> ?) AND updated_at > ? ORDER BY id DESC LIMIT 1",
            [(int) $user['id'], $purpose, $name, $size, $gameId, gmdate('Y-m-d H:i:s', time() - 48 * 3600)]
        );
        if ($existing) {
            if ($expected) {
                DB::update('uploads', ['expected_sha256' => $expected], 'uuid = ?', [$existing['uuid']]);
            }
            return self::status($existing['uuid']) + ['resumed' => true];
        }

        $chunk = self::chunkSize();
        $uuid = bin2hex(random_bytes(16));
        DB::insert('uploads', [
            'uuid' => $uuid, 'user_id' => (int) $user['id'], 'purpose' => $purpose, 'game_id' => $gameId,
            'target_meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'original_name' => $name, 'extension' => $ext,
            'mime' => isset($input['mime']) ? mb_substr((string) $input['mime'], 0, 100) : null,
            'size_bytes' => $size, 'chunk_size' => $chunk, 'total_chunks' => (int) ceil($size / $chunk),
            'expected_sha256' => $expected ?: null, 'disk' => $disk, 'status' => 'uploading',
            'ip' => Request::$current?->ip(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        @mkdir(self::chunksRoot() . '/' . $uuid, 0775, true);
        return self::status($uuid) + ['resumed' => false];
    }

    private static function load(string $uuid): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $uuid)) {
            throw new HttpException(404, 'عملية الرفع غير موجودة');
        }
        $up = DB::one('SELECT * FROM uploads WHERE uuid = ?', [$uuid]) ?? throw new HttpException(404, 'عملية الرفع غير موجودة');
        $user = Auth::user();
        if (!$user || ((int) $up['user_id'] !== (int) $user['id'] && !Auth::can('files.manage'))) {
            throw new HttpException(404, 'عملية الرفع غير موجودة');
        }
        return $up;
    }

    public static function status(string $uuid, bool $withChunks = true): array
    {
        $up = self::load($uuid);
        $out = [
            'upload_id' => $up['uuid'],
            'purpose' => $up['purpose'],
            'status' => $up['status'],
            'filename' => $up['original_name'],
            'size' => (int) $up['size_bytes'],
            'chunk_size' => (int) $up['chunk_size'],
            'total_chunks' => (int) $up['total_chunks'],
            'received_chunks' => (int) $up['received_chunks'],
            'received_bytes' => (int) $up['received_bytes'],
            'progress' => $up['size_bytes'] > 0 ? round($up['received_bytes'] / $up['size_bytes'] * 100, 2) : 0,
            'sha256' => $up['sha256'],
            'error' => $up['error'],
            'result' => $up['result'] ? json_decode($up['result'], true) : null,
            'game_id' => $up['game_id'] ? (int) $up['game_id'] : null,
            'created_at' => iso_date($up['created_at']),
            'updated_at' => iso_date($up['updated_at']),
        ];
        if ($withChunks && $up['status'] === 'uploading') {
            $out['received'] = array_map('intval', DB::column('SELECT chunk_index FROM upload_chunks WHERE upload_id = ? ORDER BY chunk_index', [(int) $up['id']]));
        }
        return $out;
    }

    public static function receiveChunk(string $uuid, int $index, Request $req): array
    {
        $up = self::load($uuid);
        if ($up['status'] !== 'uploading') {
            throw new HttpException(409, 'عملية الرفع ليست في وضع الاستقبال (' . $up['status'] . ')', [], 'upload_not_receiving');
        }
        $total = (int) $up['total_chunks'];
        if ($index < 0 || $index >= $total) {
            throw ValidationException::single('chunk_index', 'رقم الجزء خارج النطاق');
        }
        $expectedSize = $index === $total - 1 ? (int) $up['size_bytes'] - $index * (int) $up['chunk_size'] : (int) $up['chunk_size'];
        $dir = self::chunksRoot() . '/' . $up['uuid'];
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $dir . '/' . $index . '.tmp-' . bin2hex(random_bytes(4));

        $file = $req->file('chunk');
        if ($file) {
            if (!$file->isValid() || !move_uploaded_file($file->tmpPath, $tmp)) {
                throw ValidationException::single('chunk', $file->errorMessage());
            }
        } elseif (isset($req->json()['data_base64'])) {
            $bin = base64_decode((string) $req->json()['data_base64'], true);
            if ($bin === false) {
                throw ValidationException::single('chunk', 'ترميز Base64 غير صالح');
            }
            file_put_contents($tmp, $bin);
        } else {
            $in = fopen('php://input', 'rb');
            $out = fopen($tmp, 'wb');
            if (!$in || !$out) {
                throw new HttpException(500, 'تعذر استقبال الجزء');
            }
            stream_copy_to_stream($in, $out, $expectedSize + 1);
            fclose($in);
            fclose($out);
        }
        clearstatcache(true, $tmp);
        $got = (int) @filesize($tmp);
        if ($got !== $expectedSize) {
            @unlink($tmp);
            throw new HttpException(422, "حجم الجزء {$index} غير صحيح: المتوقع {$expectedSize} بايت والمستلم {$got}", [], 'chunk_size_mismatch');
        }
        $hash = (string) hash_file('sha256', $tmp);
        $claimed = $req->header('X-Chunk-Sha256') ?? $req->input('chunk_sha256');
        if (is_string($claimed) && $claimed !== '' && !hash_equals(strtolower(trim($claimed)), $hash)) {
            @unlink($tmp);
            throw new HttpException(422, "فشل التحقق من سلامة الجزء {$index} (SHA-256 غير مطابق)", [], 'chunk_checksum_mismatch');
        }
        $final = $dir . '/' . $index . '.part';
        rename($tmp, $final);
        DB::transaction(static function () use ($up, $index, $got, $hash): void {
            $inserted = DB::run('INSERT IGNORE INTO upload_chunks (upload_id, chunk_index, size, sha256, created_at) VALUES (?, ?, ?, ?, ?)',
                [(int) $up['id'], $index, $got, $hash, now()])->rowCount();
            if ($inserted) {
                DB::run('UPDATE uploads SET received_chunks = received_chunks + 1, received_bytes = received_bytes + ?, updated_at = ? WHERE id = ?',
                    [$got, now(), (int) $up['id']]);
            } else {
                DB::run('UPDATE upload_chunks SET sha256 = ? WHERE upload_id = ? AND chunk_index = ?', [$hash, (int) $up['id'], $index]);
                DB::run('UPDATE uploads SET updated_at = ? WHERE id = ?', [now(), (int) $up['id']]);
            }
        });
        $fresh = DB::one('SELECT received_chunks, received_bytes FROM uploads WHERE id = ?', [(int) $up['id']]);
        return [
            'upload_id' => $up['uuid'], 'chunk_index' => $index, 'chunk_sha256' => $hash,
            'received_chunks' => (int) $fresh['received_chunks'], 'received_bytes' => (int) $fresh['received_bytes'],
            'total_chunks' => $total, 'complete' => (int) $fresh['received_chunks'] >= $total,
        ];
    }

    public static function complete(string $uuid, ?string $sha256 = null): array
    {
        $up = self::load($uuid);
        if (in_array($up['status'], ['completed', 'processing'], true)) {
            return self::status($uuid, false);
        }
        if ($up['status'] !== 'uploading') {
            throw new HttpException(409, 'لا يمكن إكمال عملية في الحالة: ' . $up['status'], [], 'upload_not_receiving');
        }
        $have = (int) DB::value('SELECT COUNT(*) FROM upload_chunks WHERE upload_id = ?', [(int) $up['id']]);
        if ($have < (int) $up['total_chunks']) {
            throw new HttpException(409, 'لم تكتمل الأجزاء بعد: ' . $have . ' من ' . $up['total_chunks'] . '. استعلم عن الحالة وأكمل الأجزاء الناقصة', [], 'chunks_missing');
        }
        $upd = ['status' => 'processing', 'updated_at' => now()];
        if ($sha256 !== null && $sha256 !== '') {
            if (!preg_match('/^[a-f0-9]{64}$/i', $sha256)) {
                throw ValidationException::single('sha256', 'قيمة SHA-256 غير صحيحة');
            }
            $upd['expected_sha256'] = strtolower($sha256);
        }
        $n = DB::update('uploads', $upd, "id = ? AND status = 'uploading'", [(int) $up['id']]);
        if (!$n) {
            return self::status($uuid, false);
        }
        $limit = max(1, (int) Settings::get('uploads.sync_assemble_limit_mb', 2048)) * 1024 * 1024;
        if ((int) $up['size_bytes'] > $limit) {
            Queue::push('assemble_upload', ['upload_id' => (int) $up['id']], 0, 2);
            return self::status($uuid, false);
        }
        self::assemble((int) $up['id']);
        $status = self::status($uuid, false);
        if ($status['status'] === 'failed') {
            throw new HttpException(422, (string) $status['error'], [], 'upload_failed');
        }
        return $status;
    }

    /** Joins the chunks into one file while hashing, verifies, then hands the file to its destination. */
    public static function assemble(int $uploadId): void
    {
        $up = DB::one('SELECT * FROM uploads WHERE id = ?', [$uploadId]);
        if (!$up || $up['status'] !== 'processing') {
            return;
        }
        @set_time_limit(0);
        $dir = self::chunksRoot() . '/' . $up['uuid'];
        $tmpDir = STORAGE_PATH . '/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $out = $tmpDir . '/' . $up['uuid'] . '.' . $up['extension'];
        try {
            $fp = fopen($out, 'wb');
            if (!$fp) {
                throw new \RuntimeException('تعذر إنشاء الملف المؤقت');
            }
            $ctx = hash_init('sha256');
            for ($i = 0; $i < (int) $up['total_chunks']; $i++) {
                $part = $dir . '/' . $i . '.part';
                $in = @fopen($part, 'rb');
                if (!$in) {
                    fclose($fp);
                    throw new \RuntimeException('الجزء ' . $i . ' مفقود على الخادم');
                }
                while (!feof($in)) {
                    $buf = fread($in, 1048576);
                    if ($buf === false || $buf === '') {
                        break;
                    }
                    hash_update($ctx, $buf);
                    fwrite($fp, $buf);
                }
                fclose($in);
            }
            fclose($fp);
            $sha = hash_final($ctx);
            clearstatcache(true, $out);
            if ((int) filesize($out) !== (int) $up['size_bytes']) {
                throw new \UnexpectedValueException('الحجم النهائي للملف غير مطابق للحجم المعلن');
            }
            if ($up['expected_sha256'] && !hash_equals($up['expected_sha256'], $sha)) {
                throw new \UnexpectedValueException('فشل التحقق من سلامة الملف: SHA-256 النهائي لا يطابق قيمة العميل');
            }
            $result = self::finalize($up, $out, $sha);
            DB::update('uploads', ['status' => 'completed', 'sha256' => $sha, 'error' => null, 'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'completed_at' => now(), 'updated_at' => now()], 'id = ?', [$uploadId]);
            ActivityLog::log('upload.completed', 'upload', $uploadId, 'اكتمل رفع: ' . $up['original_name'], ['size' => (int) $up['size_bytes'], 'sha256' => $sha], $up['user_id'] ? (int) $up['user_id'] : null);
        } catch (\Throwable $e) {
            $msg = $e instanceof ValidationException ? $e->first() : $e->getMessage();
            DB::update('uploads', ['status' => 'failed', 'error' => mb_substr($msg, 0, 1000), 'updated_at' => now()], 'id = ?', [$uploadId]);
            ActivityLog::log('upload.failed', 'upload', $uploadId, 'فشل رفع: ' . $up['original_name'] . ' — ' . $msg, [], $up['user_id'] ? (int) $up['user_id'] : null);
            // a corrupted client upload is expected now and then (warning); anything else is a server problem (error)
            $e instanceof \UnexpectedValueException
                ? Logger::warning('Upload rejected by integrity check', ['upload' => $up['uuid'], 'error' => $msg])
                : Logger::error('Upload assemble failed', ['upload' => $up['uuid'], 'error' => $msg]);
        } finally {
            if (is_file($out)) {
                @unlink($out);
            }
            self::removeDir($dir);
        }
    }

    private static function finalize(array $up, string $path, string $sha): array
    {
        $meta = json_decode((string) $up['target_meta'], true) ?: [];
        $gameId = (int) $up['game_id'];
        switch ($up['purpose']) {
            case 'game_file':
                return ['file_id' => FileService::createFromUpload($up, $path, $sha)];
            case 'cover':
            case 'banner':
                return GameService::setImage($gameId, $up['purpose'], $path);
            case 'screenshot':
                return GameService::addScreenshot($gameId, $path, $meta['caption'] ?? null);
            case 'trailer':
                return ['trailer_url' => GameService::setTrailerFile($gameId, $path, (string) $up['original_name'])];
            case 'platform_logo':
            case 'platform_image':
                return ['urls' => CatalogService::setPlatformImage((int) $meta['platform_id'], $up['purpose'] === 'platform_logo' ? 'logo' : 'image', $path)];
            case 'avatar':
                return UserService::setAvatar((int) $up['user_id'], $path);
            case 'music':
                $rel = MediaService::storeFile($path, 'music', (string) $up['original_name']);
                MediaService::deleteFile((string) Settings::get('music.track', ''));
                Settings::set('music.track', $rel);
                return ['track' => $rel];
            case 'backup':
                $name = 'uploaded-' . gmdate('Ymd-His') . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $up['original_name']);
                rename($path, BackupService::dir() . '/' . $name);
                return ['backup' => $name];
            case 'import':
                $dest = STORAGE_PATH . '/imports/' . $up['uuid'] . '.' . $up['extension'];
                rename($path, $dest);
                return ['import_token' => $up['uuid'] . '.' . $up['extension']];
        }
        throw new \RuntimeException('Unknown purpose');
    }

    public static function cancel(string $uuid): void
    {
        $up = self::load($uuid);
        if (in_array($up['status'], ['completed', 'processing'], true)) {
            throw new HttpException(409, 'لا يمكن إلغاء عملية مكتملة أو قيد المعالجة');
        }
        DB::update('uploads', ['status' => 'cancelled', 'updated_at' => now()], 'id = ?', [(int) $up['id']]);
        DB::delete('upload_chunks', 'upload_id = ?', [(int) $up['id']]);
        self::removeDir(self::chunksRoot() . '/' . $up['uuid']);
        ActivityLog::log('upload.cancelled', 'upload', (int) $up['id'], 'إلغاء رفع: ' . $up['original_name']);
    }

    /** Expires abandoned uploads and removes orphan chunk folders. Returns the number of cleaned sessions. */
    public static function cleanupStale(int $hours = 48): int
    {
        $cut = gmdate('Y-m-d H:i:s', time() - $hours * 3600);
        $n = 0;
        foreach (DB::all("SELECT id, uuid FROM uploads WHERE status = 'uploading' AND updated_at < ?", [$cut]) as $up) {
            DB::update('uploads', ['status' => 'expired', 'updated_at' => now()], 'id = ?', [(int) $up['id']]);
            DB::delete('upload_chunks', 'upload_id = ?', [(int) $up['id']]);
            self::removeDir(self::chunksRoot() . '/' . $up['uuid']);
            $n++;
        }
        foreach (glob(self::chunksRoot() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $uuid = basename($dir);
            $st = DB::value('SELECT status FROM uploads WHERE uuid = ?', [$uuid]);
            if (($st === null || !in_array($st, ['uploading', 'processing'], true)) && filemtime($dir) < time() - 3600) {
                self::removeDir($dir);
                $n++;
            }
        }
        foreach (glob(STORAGE_PATH . '/tmp/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
        return $n;
    }

    public static function list(array $f, int $page = 1, int $perPage = 50): array
    {
        $w = ['1=1'];
        $p = [];
        if (!empty($f['status'])) { $w[] = 'u.status = :st'; $p['st'] = (string) $f['status']; }
        if (!empty($f['purpose'])) { $w[] = 'u.purpose = :pu'; $p['pu'] = (string) $f['purpose']; }
        if (!Auth::can('files.manage')) { $w[] = 'u.user_id = :me'; $p['me'] = (int) Auth::id(); }
        $where = implode(' AND ', $w);
        $total = (int) DB::value("SELECT COUNT(*) FROM uploads u WHERE $where", $p);
        $rows = DB::all("SELECT u.*, us.username, g.title AS game_title FROM uploads u LEFT JOIN users us ON us.id = u.user_id
                LEFT JOIN games g ON g.id = u.game_id WHERE $where ORDER BY u.id DESC LIMIT :lim OFFSET :off",
            $p + ['lim' => $perPage, 'off' => (max(1, $page) - 1) * $perPage]);
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                @unlink($dir . '/' . $f);
            }
        }
        @rmdir($dir);
    }
}
