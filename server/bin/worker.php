<?php
declare(strict_types=1);
/**
 * Queue worker (assembles large uploads, verifies hashes, runs backups, sends notifications).
 *   php bin/worker.php           run continuously (use systemd/supervisor)
 *   php bin/worker.php --once    process what is pending and exit
 */
require __DIR__ . '/_cli.php';
cli_require_installed();

use App\Services\Queue;

$once = isset(cli_opts($argv)['once']);
$stop = false;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stop) { $stop = true; });
    pcntl_signal(SIGINT, static function () use (&$stop) { $stop = true; });
}
do {
    $r = Queue::work($once ? 300 : 55, 500);
    if ($r['done'] || $r['failed']) {
        echo gmdate('H:i:s') . " done={$r['done']} failed={$r['failed']}\n";
    }
    if ($once) {
        break;
    }
    if (!$r['done'] && !$r['failed']) {
        sleep(3);
    }
    App\Core\DB::reset(); // keep connections fresh for long-running workers
} while (!$stop);
