<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\Config;
use App\Core\DB;
use App\Core\ValidationException;
use PDO;

/** Shared by the web installer (public/install.php) and the CLI installer (bin/install.php). */
final class Installer
{
    public const WRITABLE = ['storage', 'storage/files', 'storage/chunks', 'storage/cache', 'storage/logs', 'storage/backups',
        'storage/sessions', 'storage/tmp', 'storage/mail', 'storage/imports', 'public/media', 'config'];

    /** @return array<int,array{0:string,1:bool,2:string,3:bool}> label, ok, detail, required */
    public static function requirements(): array
    {
        $out = [['PHP 8.2 أو أحدث', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION, true]];
        foreach (['pdo_mysql' => true, 'gd' => true, 'mbstring' => true, 'fileinfo' => true, 'sodium' => true, 'json' => true, 'zlib' => true,
                     'zip' => false, 'intl' => false, 'redis' => false, 'apcu' => false, 'exif' => false] as $ext => $required) {
            $out[] = ['امتداد ' . $ext, extension_loaded($ext), $required ? 'مطلوب' : 'اختياري', $required];
        }
        $out[] = ['دعم WebP في GD', function_exists('imagewebp'), 'مطلوب لضغط الصور', true];
        $out[] = ['Argon2id لتشفير كلمات المرور', defined('PASSWORD_ARGON2ID'), 'اختياري (يُستخدم bcrypt بدونه)', false];
        foreach (self::WRITABLE as $d) {
            $path = BASE_PATH . '/' . $d;
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
            $out[] = ['قابلية الكتابة: ' . $d, is_dir($path) && is_writable($path), '', true];
        }
        $out[] = ['post_max_size', true, (string) ini_get('post_max_size') . ' (حجم جزء الرفع يُضبط تلقائياً تحته)', false];
        return $out;
    }

    public static function ready(): bool
    {
        foreach (self::requirements() as [, $ok, , $required]) {
            if ($required && !$ok) {
                return false;
            }
        }
        return true;
    }

    public static function runSqlFile(PDO $pdo, string $file): void
    {
        $sql = (string) file_get_contents($file);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $stmt) {
            if (trim($stmt) !== '') {
                $pdo->exec($stmt);
            }
        }
    }

    public static function install(array $in, ?callable $log = null): array
    {
        \App\Core\Auth::actAsSystem(true);
        try {
            return self::doInstall($in, $log ?? static fn (string $m) => null);
        } finally {
            \App\Core\Auth::actAsSystem(false);
        }
    }

    private static function doInstall(array $in, callable $log): array
    {
        $errors = [];
        $db = [
            'host' => trim((string) ($in['db_host'] ?? '127.0.0.1')), 'port' => (int) ($in['db_port'] ?? 3306),
            'database' => trim((string) ($in['db_name'] ?? '')), 'username' => trim((string) ($in['db_user'] ?? '')),
            'password' => (string) ($in['db_pass'] ?? ''), 'socket' => trim((string) ($in['db_socket'] ?? '')),
        ];
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $db['database'])) {
            $errors['db_name'] = 'اسم قاعدة البيانات يقبل حروفاً إنجليزية وأرقاماً و _ فقط';
        }
        if ($db['username'] === '') {
            $errors['db_user'] = 'اسم مستخدم قاعدة البيانات مطلوب';
        }
        $url = rtrim(trim((string) ($in['app_url'] ?? '')), '/');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#', $url)) {
            $errors['app_url'] = 'رابط الموقع غير صحيح، مثال: https://library.example.com';
        }
        $tz = (string) ($in['timezone'] ?? 'Asia/Qatar');
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'المنطقة الزمنية غير صحيحة';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        $log('الاتصال بقاعدة البيانات…');
        $dsn = $db['socket'] !== '' ? "mysql:unix_socket={$db['socket']};charset=utf8mb4" : "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
        } catch (\PDOException $e) {
            throw ValidationException::single('db_host', 'تعذر الاتصال بقاعدة البيانات: ' . $e->getMessage());
        }
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $isMaria = stripos($version, 'mariadb') !== false;
        // window functions (search) and JSON columns: MySQL 8.0+ or MariaDB 10.5+
        if (version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?: '0', $isMaria ? '10.5' : '8.0', '<')) {
            throw ValidationException::single('db_host', 'إصدار قاعدة البيانات قديم (' . $version . '). المطلوب MySQL 8.0+ أو MariaDB 10.5+');
        }
        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\PDOException) {
            // no CREATE privilege: the database must already exist
        }
        try {
            $pdo->exec("USE `{$db['database']}`");
        } catch (\PDOException $e) {
            throw ValidationException::single('db_name', 'قاعدة البيانات غير موجودة ولا توجد صلاحية لإنشائها: ' . $e->getMessage());
        }
        if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn() > 0
            && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0 && empty($in['force'])) {
            throw ValidationException::single('db_name', 'قاعدة البيانات تحتوي بيانات مكتبة سابقة. استخدم قاعدة فارغة أو استعد نسخة احتياطية.');
        }
        $pdo->exec("SET NAMES utf8mb4, time_zone = '+00:00'");
        $log('إنشاء الجداول…');
        self::runSqlFile($pdo, BASE_PATH . '/database/schema.sql');
        self::runSqlFile($pdo, BASE_PATH . '/database/seed.sql');

        $key = bin2hex(random_bytes(32));
        self::writeConfig($url, $tz, $db, $key);
        Config::load();
        DB::reset();
        Cache::useStore(null);
        Settings::forget();

        $log('إنشاء حساب المدير الرئيسي…');
        $adminId = UserService::create([
            'username' => $in['admin_username'] ?? '', 'email' => $in['admin_email'] ?? '', 'password' => $in['admin_password'] ?? '',
        ], 1, true);
        $host = parse_url($url, PHP_URL_HOST) ?: 'localhost';
        Settings::setMany([
            'site.name' => trim((string) ($in['site_name'] ?? '')) ?: 'ULTIMATE PS LIBRARY',
            'mail.from_address' => 'no-reply@' . (str_contains($host, '.') ? $host : 'example.com'),
        ]);

        $log('تجهيز صور الأجهزة…');
        foreach (DB::all('SELECT id, slug FROM platforms') as $p) {
            $dir = BASE_PATH . '/database/assets/platforms/';
            if (is_file($dir . $p['slug'] . '-logo.png')) {
                CatalogService::setPlatformImage((int) $p['id'], 'logo', $dir . $p['slug'] . '-logo.png');
            }
            if (is_file($dir . $p['slug'] . '.png')) {
                CatalogService::setPlatformImage((int) $p['id'], 'image', $dir . $p['slug'] . '.png');
            }
        }
        $demo = null;
        if (!empty($in['demo'])) {
            $log('إضافة الألعاب التجريبية…');
            $demo = DemoSeeder::run($adminId, $log);
        }
        UpdateService::save(null, ['type' => 'announcement', 'title' => 'افتُتحت المكتبة', 'body' => 'مرحباً بكم في ' . Settings::get('site.name') . '. تصفحوا الأجهزة والتصنيفات وأضيفوا ألعابكم إلى المفضلة لتصلكم التحديثات.', 'is_published' => true]);
        @file_put_contents(STORAGE_PATH . '/installed.lock', gmdate('c'));
        Cache::bump('games');
        return ['admin_id' => $adminId, 'db_version' => $version, 'demo' => $demo];
    }

    public static function writeConfig(string $url, string $tz, array $db, string $key): void
    {
        $file = BASE_PATH . '/config/config.php';
        if (is_file($file)) {
            throw new \RuntimeException('config/config.php already exists');
        }
        $q = static fn ($v) => var_export($v, true);
        $php = <<<PHP
<?php
// ULTIMATE PS LIBRARY — generated by the installer on {$q(gmdate('Y-m-d H:i'))} UTC.
// Keep this file private. Runtime options (mail, limits, security…) are edited from the admin panel.
return [
    'app' => [
        'url' => {$q($url)},
        'env' => 'production',
        'debug' => false,
        'timezone' => {$q($tz)},
        'key' => {$q($key)}, // never change after install: it encrypts stored secrets and signs download links
        // 'base_path' => '',  // set only if URLs are wrong when the app lives in a sub-folder
    ],
    'db' => [
        'host' => {$q($db['host'])},
        'port' => {$q($db['port'])},
        'socket' => {$q($db['socket'])},
        'database' => {$q($db['database'])},
        'username' => {$q($db['username'])},
        'password' => {$q($db['password'])},
    ],
    'cache' => [
        'driver' => 'auto', // auto | redis | apcu | file
        'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'password' => null, 'database' => 0, 'prefix' => {$q('upsl:' . substr(hash('sha256', $key), 0, 8) . ':')}],
    ],
    'session' => ['name' => 'UPSLSESS', 'secure' => 'auto'],
    'storage' => [
        // Add more disks (second drive, NAS, rclone/s3fs mount…) and choose the default one in Admin › Security.
        'disks' => ['local' => __DIR__ . '/../storage/files'],
        'x_sendfile' => false,              // 'apache' (mod_xsendfile) or 'nginx' (X-Accel-Redirect) for faster downloads
        'nginx_internal_prefix' => '/protected-files',
    ],
    'security' => [
        'trusted_proxies' => [],            // e.g. ['127.0.0.1'] behind a reverse proxy / CDN
        'force_https' => false,             // set to true once SSL is active
    ],
];

PHP;
        if (file_put_contents($file, $php) === false) {
            throw new \RuntimeException('Cannot write config/config.php');
        }
        @chmod($file, 0640);
    }
}
