<?php
declare(strict_types=1);

/*
 * ULTIMATE PS LIBRARY — application bootstrap.
 * Pure PHP 8.2+, no framework and no Composer dependencies, so it runs on any
 * standard hosting with PDO MySQL, GD, mbstring, fileinfo and sodium.
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('PUBLIC_PATH', BASE_PATH . '/public');
define('APP_VERSION', '1.0.0');

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require APP_PATH . '/helpers.php';

App\Core\Config::load();
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
App\Core\ErrorHandler::register();
