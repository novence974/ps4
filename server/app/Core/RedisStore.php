<?php
declare(strict_types=1);

namespace App\Core;

final class RedisStore implements CacheStore
{
    public function __construct(private \Redis $redis, private string $prefix)
    {
    }

    public function get(string $key): mixed
    {
        $v = $this->redis->get($this->prefix . $key);
        return ($v === false || $v === null) ? null : json_decode($v, true);
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $this->redis->setex($this->prefix . $key, max(1, $ttl), json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function delete(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }

    public function increment(string $key, int $ttl): int
    {
        $k = $this->prefix . $key;
        $n = (int) $this->redis->incr($k);
        if ($n === 1) {
            $this->redis->expire($k, max(1, $ttl));
        }
        return $n;
    }

    public function flush(): void
    {
        $it = null;
        do {
            $keys = $this->redis->scan($it, $this->prefix . '*', 500);
            if ($keys) {
                $this->redis->del($keys);
            }
        } while ($it > 0);
    }

    public function name(): string
    {
        return 'redis';
    }
}
