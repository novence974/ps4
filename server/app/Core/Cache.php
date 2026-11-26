<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Cache facade. driver=auto picks Redis (if the extension and server are available),
 * then APCu (web SAPI only), then the file store. Namespaced versions allow O(1) invalidation.
 */
final class Cache
{
    private static ?CacheStore $store = null;

    public static function store(): CacheStore
    {
        if (self::$store) {
            return self::$store;
        }
        $driver = (string) Config::get('cache.driver', 'auto');
        $marker = STORAGE_PATH . '/cache/.redis-down';
        if (($driver === 'redis' || $driver === 'auto') && extension_loaded('redis')
            && !(is_file($marker) && filemtime($marker) > time() - 60)) {
            try {
                $c = (array) Config::get('cache.redis');
                $r = new \Redis();
                if ($r->connect((string) ($c['host'] ?? '127.0.0.1'), (int) ($c['port'] ?? 6379), 0.5)) {
                    if (!empty($c['password'])) {
                        $r->auth($c['password']);
                    }
                    $r->select((int) ($c['database'] ?? 0));
                    return self::$store = new RedisStore($r, (string) ($c['prefix'] ?? self::defaultPrefix()));
                }
            } catch (\Throwable $e) {
                @touch($marker);
                if ($driver === 'redis') {
                    Logger::warning('Redis unavailable, falling back to file cache', ['error' => $e->getMessage()]);
                }
            }
        }
        // APCu only when chosen explicitly: its memory is not shared with CLI tools (cron/worker), so their
        // cache invalidations would not reach the web pages. "auto" therefore falls back to the shared file cache.
        if ($driver === 'apcu' && PHP_SAPI !== 'cli' && function_exists('apcu_enabled') && apcu_enabled()) {
            return self::$store = new ApcuStore(self::defaultPrefix());
        }
        return self::$store = new FileStore(STORAGE_PATH . '/cache');
    }

    public static function useStore(?CacheStore $store): void
    {
        self::$store = $store;
    }

    public static function driver(): string
    {
        return self::store()->name();
    }

    public static function get(string $key): mixed
    {
        try {
            return self::store()->get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function set(string $key, mixed $value, int $ttl = 300): void
    {
        try {
            self::store()->set($key, $value, $ttl);
        } catch (\Throwable) {
        }
    }

    public static function delete(string $key): void
    {
        try {
            self::store()->delete($key);
        } catch (\Throwable) {
        }
    }

    public static function remember(string $key, int $ttl, callable $fn): mixed
    {
        $v = self::get($key);
        if ($v !== null) {
            return $v;
        }
        $v = $fn();
        if ($v !== null) {
            self::set($key, $v, $ttl);
        }
        return $v;
    }

    /** Unique per installation, so two libraries can share one Redis/APCu without mixing their data. */
    public static function defaultPrefix(): string
    {
        return 'upsl:' . substr(hash('sha256', (string) Config::get('app.key', '')), 0, 8) . ':';
    }

    public static function flush(): void
    {
        self::store()->flush();
    }

    public static function version(string $ns): int
    {
        return (int) (self::get('ver:' . $ns) ?? 1);
    }

    public static function bump(string $ns): void
    {
        self::set('ver:' . $ns, self::version($ns) + 1, 86400 * 30);
    }

    public static function key(string $ns, string $suffix): string
    {
        return $ns . ':v' . self::version($ns) . ':' . $suffix;
    }
}
