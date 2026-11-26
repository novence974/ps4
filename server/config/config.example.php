<?php
// Copy to config.php only for manual installs; the installer (public/install.php or bin/install.php) writes it for you.
return [
    'app' => [
        'url' => 'https://library.example.com',
        'env' => 'production',
        'debug' => false,
        'timezone' => 'Asia/Qatar',
        'key' => 'CHANGE-ME-64-hex-chars-from: php -r "echo bin2hex(random_bytes(32));"',
    ],
    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'socket' => '', 'database' => 'upsl', 'username' => 'upsl', 'password' => ''],
    // driver auto = Redis when reachable, otherwise files. 'apcu' only if no CLI tool needs to clear the cache.
    // prefix: unique per installation when several libraries share one Redis.
    'cache' => ['driver' => 'auto', 'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'password' => null, 'database' => 0, 'prefix' => 'upsl:']],
    'session' => ['name' => 'UPSLSESS', 'secure' => 'auto'],
    'storage' => [
        'disks' => ['local' => __DIR__ . '/../storage/files' /*, 'nas' => '/mnt/nas/ps-library' */],
        'x_sendfile' => false,
        'nginx_internal_prefix' => '/protected-files',
    ],
    'security' => ['trusted_proxies' => [], 'force_https' => false],
];
