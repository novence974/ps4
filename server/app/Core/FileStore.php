<?php
declare(strict_types=1);

namespace App\Core;

final class FileStore implements CacheStore
{
    public function __construct(private string $dir)
    {
    }

    private function path(string $key): string
    {
        $h = sha1($key);
        return $this->dir . '/' . substr($h, 0, 2) . '/' . $h . '.cache';
    }

    public function get(string $key): mixed
    {
        $file = $this->path($key);
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['e'] ?? 0) < time()) {
            @unlink($file);
            return null;
        }
        return $data['v'] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $file = $this->path($key);
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode(['e' => time() + max(1, $ttl), 'v' => $value], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) !== false) {
            @rename($tmp, $file);
        }
    }

    public function delete(string $key): void
    {
        @unlink($this->path($key));
    }

    public function increment(string $key, int $ttl): int
    {
        $file = $this->path($key);
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return 1;
        }
        flock($fp, LOCK_EX);
        $data = json_decode((string) stream_get_contents($fp), true);
        if (!is_array($data) || ($data['e'] ?? 0) < time()) {
            $data = ['e' => time() + max(1, $ttl), 'v' => 0];
        }
        $data['v'] = (int) $data['v'] + 1;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $data['v'];
    }

    public function flush(): void
    {
        foreach (glob($this->dir . '/*/*.cache') ?: [] as $f) {
            @unlink($f);
        }
    }

    public function name(): string
    {
        return 'file';
    }
}
