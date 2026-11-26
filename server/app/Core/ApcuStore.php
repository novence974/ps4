<?php
declare(strict_types=1);

namespace App\Core;

final class ApcuStore implements CacheStore
{
    public function __construct(private string $prefix)
    {
    }

    public function get(string $key): mixed
    {
        $ok = false;
        $v = apcu_fetch($this->prefix . $key, $ok);
        return $ok ? $v : null;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        apcu_store($this->prefix . $key, $value, max(1, $ttl));
    }

    public function delete(string $key): void
    {
        apcu_delete($this->prefix . $key);
    }

    public function increment(string $key, int $ttl): int
    {
        $k = $this->prefix . $key;
        if (apcu_add($k, 1, max(1, $ttl))) {
            return 1;
        }
        $n = apcu_inc($k);
        return $n === false ? 1 : (int) $n;
    }

    public function flush(): void
    {
        if (class_exists(\APCUIterator::class)) {
            apcu_delete(new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/'));
        }
    }

    public function name(): string
    {
        return 'apcu';
    }
}
