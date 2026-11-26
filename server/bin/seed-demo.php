<?php
declare(strict_types=1);
/** Adds the fictional demo catalogue (covers, screenshots, versions, demo files):  php bin/seed-demo.php */
require __DIR__ . '/_cli.php';
cli_require_installed();

use App\Core\DB;
use App\Services\DemoSeeder;

if (DB::value("SELECT 1 FROM games WHERE product_code LIKE 'DEMO-%' LIMIT 1") && !isset(cli_opts($argv)['force'])) {
    fwrite(STDERR, "Demo games already present (use --force to add again).\n");
    exit(1);
}
$admin = DB::value("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'super_admin' ORDER BY u.id LIMIT 1");
$r = DemoSeeder::run($admin ? (int) $admin : null, static function (string $m): void { echo $m . PHP_EOL; });
echo "Done: {$r['games']} games, {$r['files']} demo files.\n";
