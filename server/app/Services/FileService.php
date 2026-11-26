<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\FileStreamer;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Core\Validator;

/**
 * Hosted game files (outside the web root) and authorised downloads through short-lived signed URLs.
 */
final class FileService
{
    public const TYPES = ['game', 'update', 'dlc', 'data', 'save', 'other'];
    public const ACCESS = ['public', 'registered', 'verified', 'moderator', 'admin'];

    public static function disks(): array
    {
        return (array) Config::get('storage.disks', ['local' => STORAGE_PATH . '/files']);
    }

    public static function diskRoot(string $disk): string
    {
        $root = self::disks()[$disk] ?? null;
        if (!$root) {
            throw new HttpException(500, 'قرص التخزين غير معرّف: ' . $disk);
        }
        if (!is_dir($root)) {
            @mkdir($root, 0775, true);
        }
        return rtrim($root, '/\\');
    }

    public static function absolutePath(array $file): string
    {
        $root = realpath(self::diskRoot((string) $file['disk']));
        $path = realpath(self::diskRoot((string) $file['disk']) . '/' . $file['path']);
        if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new HttpException(404, 'الملف غير موجود على الخادم');
        }
        return $path;
    }

    public static function find(int $id): ?array
    {
        return DB::one('SELECT f.*, g.title AS game_title, g.slug AS game_slug, g.is_published AS game_published
                          FROM game_files f JOIN games g ON g.id = f.game_id WHERE f.id = ?', [$id]);
    }

    /** Moves an assembled upload into place and registers it. */
    public static function createFromUpload(array $upload, string $tmpPath, string $sha256): int
    {
        $meta = json_decode((string) ($upload['target_meta'] ?? '{}'), true) ?: [];
        $gameId = (int) $upload['game_id'];
        $disk = (string) $upload['disk'];
        $rel = 'games/' . $gameId . '/' . gmdate('Ym') . '/' . bin2hex(random_bytes(6)) . '-' . Str::safeFilename((string) $upload['original_name']);
        $abs = self::diskRoot($disk) . '/' . $rel;
        if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0775, true) && !is_dir(dirname($abs))) {
            throw new \RuntimeException('Cannot create storage directory');
        }
        if (!@rename($tmpPath, $abs)) {
            if (!@copy($tmpPath, $abs)) {
                throw new \RuntimeException('Cannot move file to storage disk');
            }
            @unlink($tmpPath);
        }
        @chmod($abs, 0640);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($abs) ?: 'application/octet-stream';
        $id = DB::insert('game_files', [
            'game_id' => $gameId,
            'version_id' => !empty($meta['version_id']) ? (int) $meta['version_id'] : null,
            'upload_id' => (int) $upload['id'],
            'file_type' => in_array($meta['file_type'] ?? '', self::TYPES, true) ? $meta['file_type'] : 'game',
            'label' => !empty($meta['label']) ? mb_substr((string) $meta['label'], 0, 190) : null,
            'original_name' => (string) $upload['original_name'],
            'disk' => $disk,
            'path' => $rel,
            'size_bytes' => (int) filesize($abs),
            'mime' => $mime,
            'sha256' => $sha256,
            'region' => $meta['region'] ?? null ?: null,
            'version_label' => !empty($meta['version_label']) ? mb_substr((string) $meta['version_label'], 0, 32) : null,
            'access_level' => in_array($meta['access_level'] ?? '', self::ACCESS, true) ? $meta['access_level'] : 'registered',
            'is_active' => 1,
            'verified_at' => now(),
            'created_by' => $upload['user_id'] ? (int) $upload['user_id'] : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        GameService::refreshFileStatus($gameId);
        ActivityLog::log('file.created', 'game_file', $id, 'ملف جديد: ' . $upload['original_name'], ['game_id' => $gameId, 'size' => filesize($abs)], $upload['user_id'] ? (int) $upload['user_id'] : null);
        return $id;
    }

    /**
     * Registers a file that is already on the server (seeding, or multi-GB files copied by SFTP/rsync).
     * The file is copied (or moved) onto the storage disk, hashed with SHA-256 and attached to the game.
     */
    public static function importLocal(int $gameId, string $src, string $originalName, array $meta = [], ?int $userId = null, bool $move = false): int
    {
        if (!is_file($src)) {
            throw new \RuntimeException('Source file not found: ' . $src);
        }
        if (!DB::value('SELECT 1 FROM games WHERE id = ?', [$gameId])) {
            throw new HttpException(404, 'اللعبة غير موجودة');
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '' || in_array($ext, UploadService::BLOCKED, true)) {
            throw new \RuntimeException('Blocked file type: ' . $ext);
        }
        $disk = (string) ($meta['disk'] ?? Settings::get('storage.default_disk', 'local'));
        $rel = 'games/' . $gameId . '/' . gmdate('Ym') . '/' . bin2hex(random_bytes(6)) . '-' . Str::safeFilename($originalName);
        $abs = self::diskRoot($disk) . '/' . $rel;
        if (!is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0775, true);
        }
        if ($move ? !@rename($src, $abs) : !@copy($src, $abs)) {
            throw new \RuntimeException('Cannot place file on disk ' . $disk);
        }
        @chmod($abs, 0640);
        $id = DB::insert('game_files', [
            'game_id' => $gameId,
            'version_id' => !empty($meta['version_id']) ? (int) $meta['version_id'] : null,
            'file_type' => in_array($meta['file_type'] ?? '', self::TYPES, true) ? $meta['file_type'] : 'game',
            'label' => isset($meta['label']) ? mb_substr((string) $meta['label'], 0, 190) : null,
            'original_name' => Str::safeFilename($originalName),
            'disk' => $disk,
            'path' => $rel,
            'size_bytes' => (int) filesize($abs),
            'mime' => (new \finfo(FILEINFO_MIME_TYPE))->file($abs) ?: 'application/octet-stream',
            'sha256' => hash_file('sha256', $abs),
            'region' => $meta['region'] ?? null,
            'version_label' => $meta['version_label'] ?? null,
            'access_level' => in_array($meta['access_level'] ?? '', self::ACCESS, true) ? $meta['access_level'] : 'registered',
            'is_active' => 1,
            'verified_at' => now(),
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        GameService::refreshFileStatus($gameId);
        ActivityLog::log('file.created', 'game_file', $id, 'تسجيل ملف محلي: ' . $originalName, ['game_id' => $gameId], $userId);
        return $id;
    }

    public static function update(int $id, array $input): array
    {
        $f = self::find($id) ?? throw new HttpException(404, 'الملف غير موجود');
        $d = Validator::validate($input, [
            'label' => 'nullable|string|max:190',
            'file_type' => 'required|in:' . implode(',', self::TYPES),
            'access_level' => 'required|in:' . implode(',', self::ACCESS),
            'region' => 'nullable|in:' . implode(',', GameService::REGIONS),
            'version_label' => 'nullable|string|max:32',
            'version_id' => 'nullable|int',
            'is_active' => 'bool',
        ], ['label' => 'الوصف', 'file_type' => 'نوع الملف', 'access_level' => 'صلاحية التنزيل'], true);
        if (!empty($d['version_id']) && !DB::value('SELECT 1 FROM game_versions WHERE id = ? AND game_id = ?', [$d['version_id'], $f['game_id']])) {
            throw \App\Core\ValidationException::single('version_id', 'الإصدار لا يتبع هذه اللعبة');
        }
        $row = [];
        foreach ($d as $k => $v) {
            $row[$k] = is_bool($v) ? (int) $v : ($v === '' ? null : $v);
        }
        $row['updated_at'] = now();
        DB::update('game_files', $row, 'id = ?', [$id]);
        GameService::refreshFileStatus((int) $f['game_id']);
        ActivityLog::log('file.updated', 'game_file', $id, 'تعديل ملف: ' . $f['original_name']);
        return self::find($id);
    }

    public static function unlinkPhysical(array $file): void
    {
        try {
            $abs = self::absolutePath($file);
            $root = (string) realpath(self::diskRoot((string) $file['disk']));
            @unlink($abs);
            // remove the now-empty month and game folders, never the disk root itself
            for ($dir = dirname($abs); $root !== '' && str_starts_with($dir, $root . DIRECTORY_SEPARATOR); $dir = dirname($dir)) {
                if (!is_dir($dir) || count(scandir($dir) ?: []) !== 2 || !@rmdir($dir)) {
                    break;
                }
            }
        } catch (\Throwable) {
        }
    }

    public static function delete(int $id): void
    {
        $f = self::find($id) ?? throw new HttpException(404, 'الملف غير موجود');
        self::unlinkPhysical($f);
        DB::delete('game_files', 'id = ?', [$id]);
        GameService::refreshFileStatus((int) $f['game_id']);
        ActivityLog::log('file.deleted', 'game_file', $id, 'حذف ملف: ' . $f['original_name'], ['game_id' => (int) $f['game_id']]);
    }

    /** Re-computes SHA-256 from disk and compares with the stored hash. */
    public static function verifyIntegrity(int $id): array
    {
        $f = self::find($id) ?? throw new HttpException(404, 'الملف غير موجود');
        $abs = self::absolutePath($f);
        $hash = hash_file('sha256', $abs);
        $ok = $f['sha256'] ? hash_equals($f['sha256'], (string) $hash) : true;
        DB::update('game_files', ['verified_at' => $ok ? now() : null, 'sha256' => $f['sha256'] ?: $hash, 'size_bytes' => filesize($abs)], 'id = ?', [$id]);
        ActivityLog::log('file.verified', 'game_file', $id, ($ok ? 'سليم: ' : 'غير مطابق: ') . $f['original_name']);
        return ['ok' => $ok, 'sha256' => $hash, 'expected' => $f['sha256']];
    }

    // ------------------------------------------------------------------ access control

    public static function denyReason(?array $user, array $file): ?string
    {
        if (!(int) $file['is_active'] || !(int) ($file['game_published'] ?? 1)) {
            return ($user && Auth::can('files.manage')) ? null : 'هذا الملف غير متاح حالياً';
        }
        if ($file['access_level'] === 'public') {
            return null;
        }
        if (!$user) {
            return 'سجّل الدخول لتنزيل هذا الملف';
        }
        if ($user['status'] !== 'active' || !(int) $user['can_download'] || !Auth::can('files.download')) {
            return 'التنزيل غير مسموح لحسابك، تواصل مع الإدارة';
        }
        $level = (int) $user['role_level'];
        return match ($file['access_level']) {
            'registered' => null,
            'verified' => empty($user['email_verified_at']) ? 'أكّد بريدك الإلكتروني لتنزيل هذا الملف' : null,
            'moderator' => $level >= 50 ? null : 'هذا الملف متاح للمشرفين فقط',
            'admin' => $level >= 80 ? null : 'هذا الملف متاح للمدراء فقط',
            default => 'غير مسموح',
        };
    }

    /** Issues a short-lived signed URL (works with download managers, the Android app and the PS4 app). */
    public static function issueLink(array $file, ?array $user, bool $absolute = false): array
    {
        $reason = self::denyReason($user, $file);
        if ($reason !== null) {
            throw new HttpException($user ? 403 : 401, $reason, [], 'download_not_allowed');
        }
        $uid = $user ? (int) $user['id'] : 0;
        $max = max(1, (int) Settings::get('downloads.max_links_per_hour', 60));
        [$ok, , $retry] = RateLimiter::attempt('dlink:' . ($uid ?: Request::$current?->ip()), $max, 3600);
        if (!$ok) {
            throw new HttpException(429, 'تجاوزت حد التنزيلات المسموح خلال ساعة، حاول لاحقاً', ['Retry-After' => (string) $retry]);
        }
        $exp = time() + 60 * max(5, (int) Settings::get('downloads.link_ttl_minutes', 360));
        $sig = substr(Crypto::sign("dl|{$file['id']}|{$uid}|{$exp}"), 0, 40);
        $path = 'dl/' . $file['id'] . '/' . rawurlencode(Str::safeFilename((string) $file['original_name'])) . "?u={$uid}&e={$exp}&s={$sig}";
        return [
            'url' => $absolute ? abs_url($path) : url($path),
            'expires_at' => gmdate(DATE_ATOM, $exp),
            'file_id' => (int) $file['id'],
            'name' => $file['original_name'],
            'size' => (int) $file['size_bytes'],
            'sha256' => $file['sha256'],
        ];
    }

    /** GET /dl/{id}/{name}?u=&e=&s= — validates the signature and re-checks permissions, then streams with Range support. */
    public static function streamSigned(Request $req, int $fileId): Response
    {
        $uid = (int) $req->query('u', 0);
        $exp = (int) $req->query('e', 0);
        $sig = (string) $req->query('s', '');
        if ($exp < time() || !preg_match('/^[a-f0-9]{40}$/', $sig) || !hash_equals(substr(Crypto::sign("dl|{$fileId}|{$uid}|{$exp}"), 0, 40), $sig)) {
            throw new HttpException(403, 'رابط التنزيل غير صالح أو انتهت صلاحيته، اطلب رابطاً جديداً من صفحة اللعبة');
        }
        $file = self::find($fileId) ?? throw new HttpException(404, 'الملف غير موجود');
        $user = $uid ? \App\Core\Auth::loadUser($uid) : null;
        if ($uid && !$user) {
            throw new HttpException(403, 'الحساب غير موجود');
        }
        \App\Core\Auth::set($user, 'signed');
        $reason = self::denyReason($user, $file);
        if ($reason !== null) {
            throw new HttpException(403, $reason);
        }
        $abs = self::absolutePath($file);
        $internal = null;
        if ($file['disk'] === 'local') {
            $internal = rtrim((string) Config::get('storage.nginx_internal_prefix', '/protected-files'), '/') . '/' . str_replace('%2F', '/', rawurlencode($file['path']));
        }
        return FileStreamer::response($req, $abs, (string) $file['original_name'], $file['mime'] ?: null,
            static function (int $start) use ($file, $uid, $req): void {
                if ($start === 0) {
                    self::recordDownload($file, $uid ?: null, $req);
                }
            }, $internal);
    }

    public static function recordDownload(array $file, ?int $userId, Request $req): void
    {
        try {
            $dedupe = 'dlrec:' . $file['id'] . ':' . ($userId ?: $req->ip());
            if (Cache::store()->increment($dedupe, 600) > 1) {
                return;
            }
            DB::insert('downloads', [
                'user_id' => $userId, 'game_id' => (int) $file['game_id'], 'file_id' => (int) $file['id'],
                'ip' => $req->ip(), 'user_agent' => $req->userAgent(), 'created_at' => now(),
            ]);
            DB::run('UPDATE game_files SET download_count = download_count + 1 WHERE id = ?', [(int) $file['id']]);
            ActivityLog::log('file.download', 'file', (int) $file['id'], (string) $file['original_name'], ['game_id' => (int) $file['game_id']], $userId);
            DB::run('UPDATE games SET download_count = download_count + 1 WHERE id = ?', [(int) $file['game_id']]);
        } catch (\Throwable $e) {
            \App\Core\Logger::error('recordDownload failed: ' . $e->getMessage());
        }
    }

    public static function userDownloads(int $userId, int $page = 1, int $perPage = 30): array
    {
        $total = (int) DB::value('SELECT COUNT(*) FROM downloads WHERE user_id = ?', [$userId]);
        $rows = DB::all('SELECT d.id, d.created_at, d.file_id, d.game_id, g.title, g.slug, g.cover_path, p.short_name AS platform_short,
                                f.original_name, f.file_type, f.size_bytes, f.sha256
                           FROM downloads d JOIN games g ON g.id = d.game_id JOIN platforms p ON p.id = g.platform_id
                           LEFT JOIN game_files f ON f.id = d.file_id
                          WHERE d.user_id = :uid ORDER BY d.id DESC LIMIT :lim OFFSET :off',
            ['uid' => $userId, 'lim' => $perPage, 'off' => (max(1, $page) - 1) * $perPage]);
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function adminList(array $f, int $page = 1, int $perPage = 50): array
    {
        $w = ['1=1'];
        $p = [];
        if (!empty($f['q'])) { $w[] = '(f.original_name LIKE :q OR g.title LIKE :q2 OR f.sha256 = :q3)'; $p['q'] = '%' . $f['q'] . '%'; $p['q2'] = '%' . $f['q'] . '%'; $p['q3'] = (string) $f['q']; }
        if (!empty($f['type']) && in_array($f['type'], self::TYPES, true)) { $w[] = 'f.file_type = :t'; $p['t'] = $f['type']; }
        if (!empty($f['game_id'])) { $w[] = 'f.game_id = :gid'; $p['gid'] = (int) $f['game_id']; }
        $where = implode(' AND ', $w);
        $total = (int) DB::value("SELECT COUNT(*) FROM game_files f JOIN games g ON g.id = f.game_id WHERE $where", $p);
        $rows = DB::all("SELECT f.*, g.title AS game_title, g.slug AS game_slug FROM game_files f JOIN games g ON g.id = f.game_id
                          WHERE $where ORDER BY f.id DESC LIMIT :lim OFFSET :off", $p + ['lim' => $perPage, 'off' => (max(1, $page) - 1) * $perPage]);
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }
}
