<?php
declare(strict_types=1);

namespace App\Core;

interface CacheStore
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl): void;
    public function delete(string $key): void;
    /** Increments a counter; the TTL is applied when the counter is created. */
    public function increment(string $key, int $ttl): int;
    public function flush(): void;
    public function name(): string;
}
