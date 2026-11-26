<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\DB;
use App\Core\HttpException;
use PDO;

/**
 * Backups: gzip SQL dump of every table, optionally zipped together with public media.
 * Restore takes an automatic safety backup first. Scheduled backups run from bin/cron.php.
 */
final class BackupService
{
    public static function dir(): string
    {
        $d = STORAGE_PATH . '/backups';
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return $d;
    }

    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*') ?: [] as $f) {
            if (is_file($f) && preg_match('/\.(sql|sql\.gz|zip)$/', $f)) {
                $out[] = ['name' => basename($f), 'size' => filesize($f), 'created_at' => gmdate('Y-m-d H:i:s', (int) filemtime($f)),
                    'type' => str_ends_with($f, '.zip') ? 'full' : 'database'];
            }
        }
        usort($out, static fn ($a, $b) => strcmp($b['created_at'] . $b['name'], $a['created_at'] . $a['name']));
        return $out;
    }

    public static function path(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9._-]+\.(sql|sql\.gz|zip)$/', $name) || str_contains($name, '..')) {
            throw new HttpException(404, 'النسخة غير موجودة');
        }
        $p = self::dir() . '/' . $name;
        if (!is_file($p)) {
            throw new HttpException(404, 'النسخة غير موجودة');
        }
        return $p;
    }

    public static function create(bool $includeMedia = false, string $by = 'manual'): string
    {
        @set_time_limit(0);
        $base = 'backup-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . ($includeMedia ? '-full' : '');
        $sqlGz = self::dir() . '/' . $base . '.sql.gz';
        self::dump($sqlGz);
        $file = $base . '.sql.gz';
        if ($includeMedia) {
            if (!class_exists(\ZipArchive::class)) {
                throw new HttpException(500, 'امتداد Zip غير متوفر في PHP، فعّل php-zip لعمل نسخة كاملة');
            }
            $zip = new \ZipArchive();
            $zipPath = self::dir() . '/' . $base . '.zip';
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
                throw new \RuntimeException('Cannot create zip');
            }
            $zip->addFile($sqlGz, 'database.sql.gz');
            $root = MediaService::root();
            if (is_dir($root)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile() && $f->getFilename() !== '.htaccess') {
                        $zip->addFile($f->getPathname(), 'media/' . ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/'));
                    }
                }
            }
            $zip->close();
            @unlink($sqlGz);
            $file = $base . '.zip';
        }
        Settings::set('backups.last_run', now());
        ActivityLog::log('backup.created', 'backup', null, 'نسخة احتياطية (' . $by . '): ' . $file);
        self::prune();
        return $file;
    }

    public static function dump(string $gzPath): void
    {
        $pdo = DB::pdo();
        $gz = gzopen($gzPath, 'wb6');
        if (!$gz) {
            throw new \RuntimeException('Cannot write backup file');
        }
        gzwrite($gz, "-- ULTIMATE PS LIBRARY backup\n-- Created: " . gmdate('Y-m-d H:i:s') . " UTC\n-- Version: " . APP_VERSION . "\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n\n");
        $tables = array_map(static fn ($r) => array_values($r)[0], DB::all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));
        foreach ($tables as $t) {
            $create = DB::one("SHOW CREATE TABLE `{$t}`");
            gzwrite($gz, "DROP TABLE IF EXISTS `{$t}`;\n" . $create['Create Table'] . ";\n\n");
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            try {
                $stmt = $pdo->query("SELECT * FROM `{$t}`");
                $batch = [];
                $cols = null;
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $cols ??= '`' . implode('`, `', array_keys($row)) . '`';
                    $vals = [];
                    foreach ($row as $v) {
                        $vals[] = $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v));
                    }
                    $batch[] = '(' . implode(', ', $vals) . ')';
                    if (count($batch) >= 200) {
                        gzwrite($gz, "INSERT INTO `{$t}` ({$cols}) VALUES\n" . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }
                if ($batch) {
                    gzwrite($gz, "INSERT INTO `{$t}` ({$cols}) VALUES\n" . implode(",\n", $batch) . ";\n");
                }
                $stmt->closeCursor();
            } finally {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET UNIQUE_CHECKS = 1;\nSET FOREIGN_KEY_CHECKS = 1;\n");
        gzclose($gz);
    }

    public static function restore(string $name, bool $withMedia = true): string
    {
        @set_time_limit(0);
        $path = self::path($name);
        $safety = self::create(false, 'قبل الاستعادة');
        $sqlFile = $path;
        $tmp = null;
        if (str_ends_with($name, '.zip')) {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw new HttpException(422, 'ملف ZIP تالف');
            }
            $entry = $zip->locateName('database.sql.gz') !== false ? 'database.sql.gz' : ($zip->locateName('database.sql') !== false ? 'database.sql' : null);
            if (!$entry) {
                throw new HttpException(422, 'لا يحتوي الملف على قاعدة بيانات');
            }
            $tmp = STORAGE_PATH . '/tmp/restore-' . bin2hex(random_bytes(6)) . (str_ends_with($entry, '.gz') ? '.sql.gz' : '.sql');
            file_put_contents($tmp, $zip->getStream($entry) ? stream_get_contents($zip->getStream($entry)) : '');
            if ($withMedia) {
                $root = MediaService::root();
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $n = (string) $zip->getNameIndex($i);
                    if (!str_starts_with($n, 'media/') || str_ends_with($n, '/') || str_contains($n, '..') || str_contains($n, "\0")
                        || !preg_match('#^media/[A-Za-z0-9_/.\-]+\.(webp|jpg|jpeg|png|mp4|webm|mp3|ogg)$#', $n)) {
                        continue;
                    }
                    $dest = $root . '/' . substr($n, 6);
                    if (!is_dir(dirname($dest))) {
                        @mkdir(dirname($dest), 0775, true);
                    }
                    $s = $zip->getStream($n);
                    if ($s) {
                        file_put_contents($dest, $s);
                    }
                }
            }
            $zip->close();
            $sqlFile = $tmp;
        }
        try {
            self::import($sqlFile);
        } finally {
            if ($tmp) {
                @unlink($tmp);
            }
        }
        Cache::flush();
        Settings::forget();
        ActivityLog::log('backup.restored', 'backup', null, 'استعادة النسخة ' . $name . ' (نسخة أمان: ' . $safety . ')');
        return $safety;
    }

    public static function import(string $path): void
    {
        $gz = str_ends_with($path, '.gz');
        $fh = $gz ? gzopen($path, 'rb') : fopen($path, 'rb');
        if (!$fh) {
            throw new \RuntimeException('Cannot read SQL file');
        }
        $pdo = DB::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $buffer = '';
        try {
            while (($line = $gz ? gzgets($fh) : fgets($fh)) !== false) {
                if ($buffer === '') {
                    $t = ltrim($line);
                    if ($t === '' || str_starts_with($t, '--') || str_starts_with($t, '#')) {
                        continue;
                    }
                }
                $buffer .= $line;
                if (str_ends_with(rtrim($line), ';') && self::balanced($buffer)) {
                    $pdo->exec($buffer);
                    $buffer = '';
                }
            }
            if (trim($buffer) !== '') {
                $pdo->exec($buffer);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $gz ? gzclose($fh) : fclose($fh);
        }
    }

    private static function balanced(string $sql): bool
    {
        $s = str_replace(['\\\\', "\\'"], '', $sql);
        return substr_count($s, "'") % 2 === 0;
    }

    public static function delete(string $name): void
    {
        @unlink(self::path($name));
        ActivityLog::log('backup.deleted', 'backup', null, 'حذف نسخة: ' . $name);
    }

    public static function prune(): int
    {
        $keep = max(1, (int) Settings::get('backups.keep', 7));
        $auto = array_values(array_filter(self::list(), static fn ($b) => str_starts_with($b['name'], 'backup-')));
        $removed = 0;
        foreach (array_slice($auto, $keep) as $b) {
            @unlink(self::dir() . '/' . $b['name']);
            $removed++;
        }
        return $removed;
    }

    public static function isDue(): bool
    {
        $freq = (string) Settings::get('backups.frequency', 'daily');
        if ($freq === 'off') {
            return false;
        }
        $last = (string) Settings::get('backups.last_run', '');
        $interval = $freq === 'weekly' ? 7 * 86400 : 86400;
        return $last === '' || strtotime($last . ' UTC') <= time() - $interval;
    }
}
