<?php
declare(strict_types=1);

namespace App\Core;

/** Fixed-window rate limiter backed by the cache store (Redis INCR when available). */
final class RateLimiter
{
    /** @return array{0:bool,1:int,2:int} [allowed, remaining, retryAfterSeconds] */
    public static function attempt(string $key, int $max, int $window): array
    {
        $window = max(1, $window);
        $bucket = intdiv(time(), $window);
        try {
            $hits = Cache::store()->increment('rl:' . $key . ':' . $bucket, $window + 1);
        } catch (\Throwable) {
            return [true, $max, 0];
        }
        $retry = $window - (time() % $window);
        return [$hits <= $max, max(0, $max - $hits), $retry];
    }

    public static function hits(string $key, int $window): int
    {
        $bucket = intdiv(time(), max(1, $window));
        return (int) (Cache::get('rl:' . $key . ':' . $bucket) ?? 0);
    }

    public static function clear(string $key, int $window): void
    {
        $bucket = intdiv(time(), max(1, $window));
        Cache::delete('rl:' . $key . ':' . $bucket);
    }
}
