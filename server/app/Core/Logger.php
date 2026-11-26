<?php
declare(strict_types=1);

namespace App\Core;

/** JSON-lines log files in storage/logs (one file per day). */
final class Logger
{
    public static function log(string $level, string $message, array $context = []): void
    {
        $r = Request::$current;
        $line = json_encode([
            't' => gmdate('Y-m-d H:i:s'),
            'level' => $level,
            'msg' => $message,
            'ctx' => $context ?: null,
            'ip' => $r?->ip(),
            'method' => $r ? ($r->server['REQUEST_METHOD'] ?? null) : 'CLI',
            'path' => $r?->path(),
            'uid' => Auth::id(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
        $dir = STORAGE_PATH . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $m, array $c = []): void { self::log('error', $m, $c); }
    public static function warning(string $m, array $c = []): void { self::log('warning', $m, $c); }
    public static function info(string $m, array $c = []): void { self::log('info', $m, $c); }

    /** Latest entries (newest first) across the most recent log files. */
    public static function tail(int $limit = 200, ?string $level = null): array
    {
        $files = glob(STORAGE_PATH . '/logs/app-*.log') ?: [];
        rsort($files);
        $out = [];
        foreach (array_slice($files, 0, 7) as $file) {
            $lines = self::lastLines($file, 2000);
            foreach (array_reverse($lines) as $line) {
                $row = json_decode($line, true);
                if (!is_array($row) || ($level && ($row['level'] ?? '') !== $level)) {
                    continue;
                }
                $out[] = $row;
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }
        return $out;
    }

    private static function lastLines(string $file, int $max): array
    {
        $size = @filesize($file) ?: 0;
        $fp = @fopen($file, 'rb');
        if (!$fp) {
            return [];
        }
        $read = min($size, 512 * 1024);
        fseek($fp, $size - $read);
        $data = (string) fread($fp, $read);
        fclose($fp);
        $lines = array_filter(explode("\n", $data));
        if ($read < $size) {
            array_shift($lines);
        }
        return array_slice(array_values($lines), -$max);
    }
}
