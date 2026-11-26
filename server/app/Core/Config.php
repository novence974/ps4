<?php
declare(strict_types=1);

namespace App\Core;

/** Infrastructure configuration (config/config.php). Behaviour settings live in the `settings` table. */
final class Config
{
    private static array $items = [];
    private static bool $installed = false;

    public static function defaults(): array
    {
        return [
            'app' => [
                'name' => 'ULTIMATE PS LIBRARY',
                'url' => 'http://localhost:8000',
                'env' => 'production',
                'debug' => false,
                'timezone' => 'Asia/Qatar',
                'key' => '',
                'base_path' => null, // null = auto-detect from SCRIPT_NAME
            ],
            'db' => [
                'host' => '127.0.0.1', 'port' => 3306, 'socket' => '',
                'database' => 'upsl', 'username' => 'upsl', 'password' => '',
            ],
            'cache' => [
                'driver' => 'auto', // auto | redis | apcu | file
                'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'password' => null, 'database' => 0, 'prefix' => 'upsl:'],
            ],
            'session' => ['name' => 'UPSLSESS', 'secure' => 'auto', 'absolute_seconds' => 604800],
            'mail' => ['driver' => 'log'],
            'storage' => [
                // Extra disks (NAS, second drive, rclone/s3fs mounts…) can be added here: 'nas' => '/mnt/nas/ps-library'
                'disks' => ['local' => BASE_PATH . '/storage/files'],
                'x_sendfile' => false,               // false | 'apache' | 'nginx'
                'nginx_internal_prefix' => '/protected-files',
            ],
            'security' => ['trusted_proxies' => [], 'force_https' => false],
        ];
    }

    public static function load(): void
    {
        $file = BASE_PATH . '/config/config.php';
        self::$items = self::defaults();
        if (is_file($file)) {
            $user = require $file;
            if (is_array($user)) {
                self::$items = array_replace_recursive(self::$items, $user);
                self::$installed = true;
            }
        }
    }

    public static function installed(): bool
    {
        return self::$installed;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $ref = &self::$items;
        foreach (explode('.', $key) as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
    }
}
