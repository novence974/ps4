<?php
declare(strict_types=1);
/**
 * Scheduler — add to crontab:   * * * * * php /path/to/server/bin/cron.php >> /path/to/server/storage/logs/cron.log 2>&1
 * Runs queued jobs, expires abandoned uploads, makes scheduled backups and prunes old data.
 */
require __DIR__ . '/_cli.php';
cli_require_installed();

use App\Core\DB;
use App\Services\BackupService;
use App\Services\Queue;
use App\Services\Settings;
use App\Services\UploadService;

$lock = fopen(STORAGE_PATH . '/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // previous run still busy
}
$t = microtime(true);
$recovered = Queue::recoverStuck();
$jobs = Queue::work(40, 200);
$expired = UploadService::cleanupStale(48);
$backup = null;
if (BackupService::isDue()) {
    $backup = BackupService::create((bool) Settings::get('backups.include_media', false), 'cron');
}
if ((int) gmdate('i') === 0) {
    Queue::prune(14);
    \App\Services\ActivityLog::prune();
    DB::run('DELETE FROM user_tokens WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
    DB::run('DELETE FROM api_tokens WHERE (revoked_at IS NOT NULL AND revoked_at < ?) OR (expires_at IS NOT NULL AND expires_at < ?)', [gmdate('Y-m-d H:i:s', time() - 30 * 86400), gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
    foreach (glob(STORAGE_PATH . '/logs/app-*.log') ?: [] as $f) {
        if (filemtime($f) < time() - 30 * 86400) {
            @unlink($f);
        }
    }
    foreach (glob(STORAGE_PATH . '/imports/*') ?: [] as $f) {
        if (filemtime($f) < time() - 86400) {
            @unlink($f);
        }
    }
}
Settings::set('cron.last_run', gmdate('Y-m-d H:i:s'));
$msg = sprintf('%s jobs=%d/%d expired=%d recovered=%d%s (%.1fs)', gmdate('Y-m-d H:i:s'), $jobs['done'], $jobs['failed'], $expired, $recovered, $backup ? ' backup=' . $backup : '', microtime(true) - $t);
if ($jobs['done'] || $jobs['failed'] || $expired || $backup || $recovered) {
    echo $msg . PHP_EOL;
}
flock($lock, LOCK_UN);
