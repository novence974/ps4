<?php
declare(strict_types=1);
/**
 * Command-line installer.
 *   php bin/install.php                      (interactive)
 *   php bin/install.php --db-host=127.0.0.1 --db-name=upsl --db-user=upsl --db-pass=secret \
 *       --url=https://library.example.com --admin-user=admin --admin-email=me@example.com --admin-pass='S3cret-pass' [--demo] [--timezone=Asia/Qatar]
 */
require __DIR__ . '/_cli.php';

use App\Core\Config;
use App\Core\ValidationException;
use App\Services\Installer;

if (Config::installed()) {
    fwrite(STDERR, "Already installed (config/config.php exists).\n");
    exit(1);
}
echo "ULTIMATE PS LIBRARY — installer\n\n";
$ok = true;
foreach (Installer::requirements() as [$label, $pass, $detail, $required]) {
    printf("  [%s] %s %s\n", $pass ? 'OK' : ($required ? '!!' : '--'), $label, $detail);
    if ($required && !$pass) {
        $ok = false;
    }
}
if (!$ok) {
    fwrite(STDERR, "\nFix the requirements marked !! and run again.\n");
    exit(1);
}
$o = cli_opts($argv);
$interactive = !isset($o['db-name']);
$in = [
    'db_host' => $o['db-host'] ?? ($interactive ? cli_ask('Database host', '127.0.0.1') : '127.0.0.1'),
    'db_port' => $o['db-port'] ?? ($interactive ? cli_ask('Database port', '3306') : '3306'),
    'db_socket' => $o['db-socket'] ?? '',
    'db_name' => $o['db-name'] ?? cli_ask('Database name', 'upsl'),
    'db_user' => $o['db-user'] ?? cli_ask('Database user', 'upsl'),
    'db_pass' => $o['db-pass'] ?? ($interactive ? cli_ask('Database password', '', true) : ''),
    'app_url' => $o['url'] ?? cli_ask('Site URL', 'http://localhost:8000'),
    'timezone' => $o['timezone'] ?? 'Asia/Qatar',
    'site_name' => $o['site-name'] ?? '',
    'admin_username' => $o['admin-user'] ?? cli_ask('Super admin username', 'admin'),
    'admin_email' => $o['admin-email'] ?? cli_ask('Super admin e-mail'),
    'admin_password' => $o['admin-pass'] ?? cli_ask('Super admin password (8+ chars, letters and digits)', '', true),
    'demo' => isset($o['demo']) || ($interactive && strtolower(cli_ask('Install the demo catalogue? (y/n)', 'y')) === 'y'),
    'force' => isset($o['force']),
];
try {
    $res = Installer::install($in, static function (string $m): void { echo '  ' . $m . PHP_EOL; });
} catch (ValidationException $e) {
    fwrite(STDERR, "\nInstallation failed:\n");
    foreach ($e->errors as $k => $v) {
        fwrite(STDERR, "  - {$k}: {$v}\n");
    }
    exit(1);
}
echo "\nDone. Database: {$res['db_version']}\n";
if ($res['demo']) {
    echo "Demo catalogue: {$res['demo']['games']} games, {$res['demo']['files']} demo files.\n";
}
echo "Next: add bin/cron.php to crontab (see docs/01-INSTALL.md) and sign in at {$in['app_url']}/login\n";
