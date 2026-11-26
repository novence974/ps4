<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\DB;

/** Dashboard statistics, server health monitoring and storage management. */
final class SystemService
{
    public static function dashboard(): array
    {
        return Cache::remember('stats:dashboard', 60, static function () {
            $d1 = gmdate('Y-m-d H:i:s', time() - 86400);
            $d7 = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
            $c = DB::one("SELECT
                (SELECT COUNT(*) FROM games) AS games_total,
                (SELECT COUNT(*) FROM games WHERE is_published = 1) AS games_published,
                (SELECT COUNT(*) FROM games WHERE created_at >= :a) AS games_new_7d,
                (SELECT COUNT(*) FROM users) AS users_total,
                (SELECT COUNT(*) FROM users WHERE created_at >= :b) AS users_new_7d,
                (SELECT COUNT(*) FROM downloads) AS downloads_total,
                (SELECT COUNT(*) FROM downloads WHERE created_at >= :c) AS downloads_24h,
                (SELECT COUNT(*) FROM game_files WHERE is_active = 1) AS files_total,
                (SELECT COALESCE(SUM(size_bytes), 0) FROM game_files) AS files_bytes,
                (SELECT COUNT(*) FROM game_files WHERE created_at >= :d) AS files_new_7d,
                (SELECT COUNT(*) FROM uploads WHERE status = 'uploading') AS uploads_active,
                (SELECT COUNT(*) FROM uploads WHERE status = 'processing') AS uploads_processing,
                (SELECT COUNT(*) FROM uploads WHERE status = 'completed' AND completed_at >= :e) AS uploads_done_7d,
                (SELECT COUNT(*) FROM uploads WHERE status = 'failed' AND updated_at >= :f) AS uploads_failed_7d,
                (SELECT COUNT(DISTINCT user_id) FROM activity_logs WHERE created_at >= :g AND user_id IS NOT NULL) AS active_users_24h,
                (SELECT COUNT(*) FROM favorites) AS favorites_total",
                ['a' => $d7, 'b' => $d7, 'c' => $d1, 'd' => $d7, 'e' => $d7, 'f' => $d7, 'g' => $d1]) ?? [];
            $c = array_map('intval', $c);
            $c['by_platform'] = DB::all('SELECT p.short_name, p.accent_color,
                    (SELECT COUNT(*) FROM games g WHERE g.platform_id = p.id) AS total,
                    (SELECT COUNT(*) FROM games g WHERE g.platform_id = p.id AND g.is_published = 1) AS published
                FROM platforms p ORDER BY p.sort_order, p.id');
            $daily = [];
            foreach (DB::all('SELECT DATE(created_at) AS d, COUNT(*) AS c FROM downloads WHERE created_at >= ? GROUP BY DATE(created_at)', [gmdate('Y-m-d 00:00:00', time() - 29 * 86400)]) as $r) {
                $daily[$r['d']] = (int) $r['c'];
            }
            $c['downloads_daily'] = [];
            for ($i = 29; $i >= 0; $i--) {
                $day = gmdate('Y-m-d', time() - $i * 86400);
                $c['downloads_daily'][] = ['date' => $day, 'count' => $daily[$day] ?? 0];
            }
            $c['top_games'] = DB::all('SELECT id, title, slug, download_count, view_count FROM games ORDER BY download_count DESC, view_count DESC LIMIT 6');
            $c['by_role'] = DB::all('SELECT r.name, r.slug, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS total FROM roles r ORDER BY r.level DESC');
            $c['media_bytes'] = MediaService::directorySize();
            $c['disks'] = self::disks();
            return $c;
        });
    }

    public static function disks(): array
    {
        $out = [];
        $used = [];
        foreach (DB::all('SELECT disk, COALESCE(SUM(size_bytes),0) AS b, COUNT(*) AS n FROM game_files GROUP BY disk') as $r) {
            $used[$r['disk']] = ['bytes' => (int) $r['b'], 'files' => (int) $r['n']];
        }
        foreach (FileService::disks() as $name => $path) {
            $total = @disk_total_space($path) ?: 0;
            $free = @disk_free_space($path) ?: 0;
            $out[] = ['name' => $name, 'total' => (int) $total, 'free' => (int) $free, 'used_percent' => $total > 0 ? round(($total - $free) / $total * 100, 1) : 0,
                'library_bytes' => $used[$name]['bytes'] ?? 0, 'library_files' => $used[$name]['files'] ?? 0, 'writable' => is_dir($path) && is_writable($path)];
        }
        return $out;
    }

    public static function health(bool $detailed = false): array
    {
        $t = microtime(true);
        try {
            DB::value('SELECT 1');
            $dbOk = true;
        } catch (\Throwable) {
            $dbOk = false;
        }
        // visitors only learn whether the server answers; everything else is for signed-in admins
        $out = [
            'status' => $dbOk ? 'ok' : 'degraded',
            'app' => (string) Settings::get('site.name'),
            'api_version' => 'v1',
            'maintenance' => (bool) Settings::get('site.maintenance', false),
            'database' => ['ok' => $dbOk],
        ];
        if ($detailed) {
            $out['version'] = APP_VERSION;
            $out['time'] = gmdate(DATE_ATOM);
            $out['registration_open'] = (bool) Settings::get('auth.registration_open', true);
            $out['database']['latency_ms'] = round((microtime(true) - $t) * 1000, 2);
            $out['chunk_size'] = UploadService::chunkSize();
            $exts = [];
            foreach (['pdo_mysql', 'gd', 'mbstring', 'fileinfo', 'sodium', 'zip', 'zlib', 'curl', 'intl', 'redis', 'apcu', 'exif', 'openssl'] as $e) {
                $exts[$e] = extension_loaded($e);
            }
            $out += [
                'php' => PHP_VERSION,
                'server' => $_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI,
                'db_version' => $dbOk ? (string) DB::value('SELECT VERSION()') : null,
                'cache' => Cache::driver(),
                'queue' => Queue::stats(),
                'last_cron' => (string) Settings::get('cron.last_run', ''),
                'last_backup' => (string) Settings::get('backups.last_run', ''),
                'disks' => self::disks(),
                'extensions' => $exts,
                'webp' => function_exists('imagewebp'),
                'limits' => [
                    'upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size'),
                    'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time'),
                ],
                'load' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
            ];
        }
        return $out;
    }

    public static function dirSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $total = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                $total += $f->getSize();
            }
        }
        return $total;
    }

    public static function storageOverview(): array
    {
        return [
            'disks' => self::disks(),
            'by_type' => DB::all('SELECT file_type, COUNT(*) AS files, COALESCE(SUM(size_bytes),0) AS bytes FROM game_files GROUP BY file_type ORDER BY bytes DESC'),
            'largest' => DB::all('SELECT f.id, f.original_name, f.size_bytes, f.file_type, g.title AS game_title, g.id AS game_id FROM game_files f JOIN games g ON g.id = f.game_id ORDER BY f.size_bytes DESC LIMIT 10'),
            'media_bytes' => MediaService::directorySize(),
            'chunks_bytes' => self::dirSize(UploadService::chunksRoot()),
            'backups_bytes' => self::dirSize(BackupService::dir()),
            'logs_bytes' => self::dirSize(STORAGE_PATH . '/logs'),
            'tmp_bytes' => self::dirSize(STORAGE_PATH . '/tmp'),
        ];
    }

    /** Files present on a disk but not registered in game_files (e.g. after a manual copy or a crash). */
    public static function orphans(int $limit = 500): array
    {
        $out = [];
        foreach (FileService::disks() as $disk => $root) {
            if (!is_dir($root)) {
                continue;
            }
            $known = array_flip(DB::column('SELECT path FROM game_files WHERE disk = ?', [$disk]));
            $rootReal = realpath($root);
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($rootReal, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if (!$f->isFile() || $f->getFilename() === '.htaccess' || $f->getFilename() === '.gitkeep') {
                    continue;
                }
                $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($rootReal))), '/');
                if (!isset($known[$rel])) {
                    $out[] = ['disk' => $disk, 'path' => $rel, 'size' => $f->getSize(), 'modified' => gmdate('Y-m-d H:i:s', $f->getMTime())];
                    if (count($out) >= $limit) {
                        return $out;
                    }
                }
            }
        }
        return $out;
    }

    public static function deleteOrphans(): array
    {
        $n = 0;
        $bytes = 0;
        foreach (self::orphans(5000) as $o) {
            $abs = realpath(FileService::diskRoot($o['disk']) . '/' . $o['path']);
            $root = realpath(FileService::diskRoot($o['disk']));
            if ($abs && $root && str_starts_with($abs, $root . DIRECTORY_SEPARATOR) && @unlink($abs)) {
                $n++;
                $bytes += $o['size'];
            }
        }
        ActivityLog::log('storage.cleanup', 'storage', null, "حذف {$n} ملف يتيم (" . human_bytes($bytes) . ')');
        return ['deleted' => $n, 'bytes' => $bytes];
    }
}
