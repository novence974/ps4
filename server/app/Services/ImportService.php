<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\Str;
use App\Core\ValidationException;
use App\Core\Validator;

/**
 * Bulk import from CSV or JSON with a dry-run preview, validation per row and duplicate detection
 * (inside the database and inside the file). Commit strategies for duplicates: skip | update | create.
 */
final class ImportService
{
    public const MAX_ROWS = 5000;

    private const ALIASES = [
        'name' => 'title', 'game' => 'title', 'اسم اللعبة' => 'title', 'العنوان' => 'title',
        'console' => 'platform', 'system' => 'platform', 'الجهاز' => 'platform',
        'year' => 'release_year', 'سنة الإصدار' => 'release_year',
        'genre' => 'categories', 'genres' => 'categories', 'category' => 'categories', 'التصنيف' => 'categories',
        'developer_name' => 'developer', 'المطور' => 'developer', 'الناشر' => 'publisher',
        'size_bytes' => 'size', 'الحجم' => 'size', 'language' => 'languages', 'اللغات' => 'languages',
        'code' => 'product_code', 'title_id' => 'product_code', 'serial' => 'product_code',
        'cover' => 'cover_url', 'image' => 'cover_url', 'صورة الغلاف' => 'cover_url', 'banner' => 'banner_url', 'صورة الخلفية' => 'banner_url',
        'trailer' => 'trailer_url', 'video' => 'trailer_url', 'is_published' => 'published', 'is_featured' => 'featured',
        'pegi' => 'age_rating', 'esrb' => 'age_rating', 'score' => 'rating', 'الوصف' => 'description',
    ];

    public static function storagePath(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}\.(csv|json)$/', $token)) {
            throw ValidationException::single('file', 'ملف الاستيراد غير صالح');
        }
        $path = STORAGE_PATH . '/imports/' . $token;
        if (!is_file($path)) {
            throw ValidationException::single('file', 'انتهت صلاحية ملف الاستيراد، ارفعه مجدداً');
        }
        return $path;
    }

    /** Saves an uploaded import file and returns its token. */
    public static function store(string $tmpPath, string $originalName): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'json'], true)) {
            throw ValidationException::single('file', 'الصيغ المقبولة: CSV أو JSON');
        }
        if (filesize($tmpPath) > 30 * 1024 * 1024) {
            throw ValidationException::single('file', 'حجم ملف الاستيراد أكبر من 30MB');
        }
        $token = bin2hex(random_bytes(16)) . '.' . $ext;
        $dir = STORAGE_PATH . '/imports';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!@move_uploaded_file($tmpPath, $dir . '/' . $token) && !@copy($tmpPath, $dir . '/' . $token)) {
            throw new \RuntimeException('Cannot store import file');
        }
        return $token;
    }

    public static function parse(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $rows = [];
        if ($ext === 'json') {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data) && isset($data['games']) && is_array($data['games'])) {
                $data = $data['games'];
            }
            if (!is_array($data) || !array_is_list($data)) {
                throw ValidationException::single('file', 'ملف JSON يجب أن يحتوي على مصفوفة من الألعاب أو كائن فيه المفتاح games');
            }
            foreach ($data as $item) {
                $rows[] = is_array($item) ? self::normalizeKeys($item) : [];
            }
        } else {
            $fp = fopen($path, 'rb');
            $first = (string) fgets($fp);
            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
            $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
            $header = array_map(static fn ($h) => mb_strtolower(trim((string) $h)), str_getcsv($first, $delim, '"', '\\'));
            while (($line = fgetcsv($fp, 0, $delim, '"', '\\')) !== false) {
                if ($line === [null] || count(array_filter($line, static fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }
                $assoc = [];
                foreach ($header as $i => $h) {
                    $assoc[$h] = $line[$i] ?? null;
                }
                $rows[] = self::normalizeKeys($assoc);
                if (count($rows) > self::MAX_ROWS) {
                    break;
                }
            }
            fclose($fp);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::single('file', 'الحد الأقصى ' . self::MAX_ROWS . ' لعبة في الملف الواحد، قسّم الملف');
        }
        if (!$rows) {
            throw ValidationException::single('file', 'الملف لا يحتوي على بيانات');
        }
        return $rows;
    }

    private static function normalizeKeys(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $k = mb_strtolower(trim((string) $k));
            $out[self::ALIASES[$k] ?? $k] = is_string($v) ? trim($v) : $v;
        }
        return $out;
    }

    private static function platformId(mixed $value): ?int
    {
        $v = mb_strtolower(trim((string) $value));
        if ($v === '') {
            return null;
        }
        foreach (CatalogService::platforms(false) as $p) {
            if ($v === (string) $p['id'] || $v === strtolower($p['slug']) || $v === strtolower($p['short_name']) || $v === strtolower($p['name'])
                || str_replace(' ', '', $v) === strtolower(str_replace(' ', '', $p['name']))) {
                return (int) $p['id'];
            }
        }
        return null;
    }

    private static function bool(mixed $v): bool
    {
        return in_array(mb_strtolower(trim((string) $v)), ['1', 'true', 'yes', 'y', 'نعم', 'on', 'published'], true);
    }

    public static function analyze(array $rows, bool $isCsv = true): array
    {
        $seen = [];
        $out = [];
        $summary = ['total' => 0, 'new' => 0, 'duplicate' => 0, 'duplicate_in_file' => 0, 'invalid' => 0];
        foreach ($rows as $i => $r) {
            $platformId = self::platformId($r['platform'] ?? '');
            $categories = $r['categories'] ?? [];
            if (is_string($categories)) {
                $categories = preg_split('/[|;,]+/', $categories) ?: [];
            }
            $input = [
                'platform_id' => $platformId,
                'title' => (string) ($r['title'] ?? ''),
                'description' => $r['description'] ?? null,
                'release_year' => $r['release_year'] ?? null,
                'developer' => $r['developer'] ?? null,
                'publisher' => $r['publisher'] ?? null,
                'age_rating' => $r['age_rating'] ?? null,
                'size' => isset($r['size']) ? (string) $r['size'] : null,
                'languages' => $r['languages'] ?? null,
                'region' => isset($r['region']) && $r['region'] !== '' ? strtoupper((string) $r['region']) : null,
                'version' => $r['version'] ?? null,
                'edition' => $r['edition'] ?? null,
                'rating' => $r['rating'] ?? null,
                'product_code' => $r['product_code'] ?? null,
                'trailer_url' => $r['trailer_url'] ?? null,
                'cover_url' => isset($r['cover_url']) && trim((string) $r['cover_url']) !== '' ? trim((string) $r['cover_url']) : null,
                'banner_url' => isset($r['banner_url']) && trim((string) $r['banner_url']) !== '' ? trim((string) $r['banner_url']) : null,
                'categories' => array_values(array_filter(array_map('trim', (array) $categories))),
                'is_published' => self::bool($r['published'] ?? false),
                'is_featured' => self::bool($r['featured'] ?? false),
            ];
            $errors = [];
            if (!$platformId) {
                $errors['platform'] = 'الجهاز غير معروف: ' . ($r['platform'] ?? '(فارغ)');
            }
            try {
                Validator::validate($input, GameService::rules(), GameService::labels());
                if ($input['size'] !== null && $input['size'] !== '' && Str::parseSize($input['size']) === null) {
                    $errors['size'] = 'صيغة الحجم غير صحيحة';
                }
                if ($input['trailer_url']) {
                    GameService::validateTrailer((string) $input['trailer_url']);
                }
                foreach (['cover_url', 'banner_url'] as $k) {
                    if ($input[$k]) {
                        try {
                            GameService::validImageUrl($input[$k]);
                        } catch (ValidationException $e) {
                            $errors[$k] = $e->first();
                        }
                    }
                }
            } catch (ValidationException $e) {
                $errors += $e->errors;
            }
            $dups = ($platformId && $input['title'] !== '') ? GameService::duplicates($input['title'], $platformId, $input['product_code'] ?: null, null, $input['region'], true) : [];
            $key = $platformId . '|' . Str::normalizeTitle($input['title']) . '|' . ($input['region'] ?? '');
            $inFile = $seen[$key] ?? null;
            $seen[$key] ??= $i + ($isCsv ? 2 : 1);
            $status = $errors ? 'invalid' : ($inFile ? 'duplicate_in_file' : ($dups ? 'duplicate' : 'new'));
            $summary[$status]++;
            $summary['total']++;
            $out[] = [
                'line' => $i + ($isCsv ? 2 : 1), 'title' => $input['title'], 'platform' => (string) ($r['platform'] ?? ''),
                'status' => $status, 'errors' => $errors, 'duplicate_of' => $dups[0] ?? null, 'same_as_line' => $inFile, 'input' => $input,
            ];
        }
        return ['summary' => $summary, 'rows' => $out];
    }

    /** Image links from the file are stored as given (never downloaded). */
    private static function applyImages(int $gameId, array $images): void
    {
        foreach ($images as $kind => $url) {
            if ($gameId && $url) {
                GameService::setImageUrl($gameId, $kind, $url);
            }
        }
    }

    public static function run(string $token, string $mode, string $onDuplicate = 'skip'): array
    {
        $path = self::storagePath($token);
        $analysis = self::analyze(self::parse($path), str_ends_with($token, '.csv'));
        if ($mode !== 'commit') {
            return $analysis;
        }
        if (!in_array($onDuplicate, ['skip', 'update', 'create'], true)) {
            $onDuplicate = 'skip';
        }
        @set_time_limit(600);
        $res = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        UpdateService::$suppressAuto = true;
        try {
            foreach ($analysis['rows'] as $r) {
                if ($r['status'] === 'invalid' || $r['status'] === 'duplicate_in_file') {
                    $res['skipped']++;
                    continue;
                }
                $images = ['cover' => $r['input']['cover_url'] ?? null, 'banner' => $r['input']['banner_url'] ?? null];
                unset($r['input']['cover_url'], $r['input']['banner_url']);
                try {
                    if ($r['status'] === 'duplicate') {
                        if ($onDuplicate === 'skip') {
                            $res['skipped']++;
                            continue;
                        }
                        if ($onDuplicate === 'update') {
                            $changes = array_filter($r['input'], static fn ($v) => $v !== null && $v !== '' && $v !== []);
                            $changes['allow_duplicate'] = true;
                            GameService::save((int) $r['duplicate_of']['id'], $changes, true, false);
                            self::applyImages((int) $r['duplicate_of']['id'], $images);
                            $res['updated']++;
                            continue;
                        }
                        $r['input']['allow_duplicate'] = true;
                    }
                    $saved = GameService::save(null, $r['input'], false, false);
                    self::applyImages((int) ($saved['id'] ?? 0), $images);
                    $res['created']++;
                } catch (\Throwable $e) {
                    $res['failed']++;
                    $msg = $e instanceof ValidationException ? $e->first() : $e->getMessage();
                    $res['errors'][] = 'السطر ' . $r['line'] . ': ' . $msg;
                }
            }
        } finally {
            UpdateService::$suppressAuto = false;
        }
        @unlink($path);
        Cache::bump('games');
        ActivityLog::log('game.import', 'game', null, sprintf('استيراد: %d جديدة، %d محدثة، %d متجاهلة، %d فاشلة', $res['created'], $res['updated'], $res['skipped'], $res['failed']));
        if ($res['created'] > 0) {
            $n = (int) $res['created'];
            $what = match (true) {
                $n === 1 => 'لعبة جديدة',
                $n === 2 => 'لعبتان جديدتان',
                default => $n . ' ' . ar_noun($n, 'لعبة جديدة', 'ألعاب جديدة'),
            };
            UpdateService::save(null, ['type' => 'announcement', 'title' => 'أضيفت ' . $what . ' إلى المكتبة', 'is_published' => true]);
        }
        return $res + ['summary' => $analysis['summary']];
    }

    public static function template(string $format): string
    {
        $rows = [
            ['title' => 'Nebula Drift', 'platform' => 'ps5', 'categories' => 'racing|open-world', 'description' => 'سباقات مستقبلية بين الكواكب.', 'release_year' => 2024,
             'developer' => 'Aurora Forge', 'publisher' => 'Polar Pixel', 'age_rating' => 'PEGI 7', 'size' => '48.5 GB', 'languages' => 'ar|en|fr',
             'region' => 'EUR', 'version' => '1.02', 'edition' => 'Standard', 'rating' => 8.6, 'product_code' => 'DEMO-50001', 'trailer_url' => '', 'published' => 1, 'featured' => 0],
            ['title' => 'Crimson Vanguard', 'platform' => 'ps4', 'categories' => 'action|rpg', 'description' => 'مغامرة أكشن في عالم خيالي.', 'release_year' => 2019,
             'developer' => 'Nightjar Studios', 'publisher' => 'Atlas Peak', 'age_rating' => 'PEGI 16', 'size' => '36 GB', 'languages' => 'en|ar',
             'region' => 'USA', 'version' => '1.10', 'edition' => 'Deluxe', 'rating' => 9.1, 'product_code' => 'DEMO-40002', 'trailer_url' => '', 'published' => 0, 'featured' => 0],
        ];
        if ($format === 'json') {
            return (string) json_encode(['games' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $fp = fopen('php://temp', 'w+');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, array_keys($rows[0]), ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($fp, $r, ',', '"', '\\');
        }
        rewind($fp);
        return (string) stream_get_contents($fp);
    }
}
