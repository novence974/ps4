<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Logger;

/** Database-backed job queue processed by bin/worker.php (or bin/cron.php every minute). */
final class Queue
{
    public static function push(string $type, array $payload = [], int $delaySeconds = 0, int $maxAttempts = 3): int
    {
        return DB::insert('jobs', [
            'type' => $type,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
            'max_attempts' => max(1, $maxAttempts),
            'available_at' => gmdate('Y-m-d H:i:s', time() + $delaySeconds),
            'created_at' => now(),
        ]);
    }

    /** Atomically reserves the next job (portable: no SKIP LOCKED needed). */
    public static function reserve(): ?array
    {
        $token = bin2hex(random_bytes(16));
        $n = DB::run("UPDATE jobs SET status = 'running', reserve_token = ?, started_at = ?, attempts = attempts + 1
                       WHERE status = 'pending' AND available_at <= ? ORDER BY id LIMIT 1", [$token, now(), now()])->rowCount();
        return $n ? DB::one('SELECT * FROM jobs WHERE reserve_token = ?', [$token]) : null;
    }

    public static function work(int $maxSeconds = 50, int $maxJobs = 200): array
    {
        $start = time();
        $done = 0;
        $failed = 0;
        while (time() - $start < $maxSeconds && ($done + $failed) < $maxJobs && ($job = self::reserve())) {
            try {
                self::handle($job['type'], json_decode((string) $job['payload'], true) ?: []);
                DB::update('jobs', ['status' => 'done', 'finished_at' => now(), 'error' => null, 'reserve_token' => null], 'id = ?', [(int) $job['id']]);
                $done++;
            } catch (\Throwable $e) {
                $final = (int) $job['attempts'] >= (int) $job['max_attempts'];
                DB::update('jobs', [
                    'status' => $final ? 'failed' : 'pending',
                    'available_at' => gmdate('Y-m-d H:i:s', time() + 60 * (int) $job['attempts']),
                    'error' => mb_substr($e->getMessage(), 0, 2000),
                    'reserve_token' => null,
                    'finished_at' => $final ? now() : null,
                ], 'id = ?', [(int) $job['id']]);
                Logger::error('Job failed: ' . $job['type'], ['id' => $job['id'], 'error' => $e->getMessage()]);
                $failed++;
            }
        }
        return ['done' => $done, 'failed' => $failed];
    }

    public static function handle(string $type, array $p): void
    {
        match ($type) {
            'assemble_upload' => UploadService::assemble((int) $p['upload_id']),
            'verify_file' => FileService::verifyIntegrity((int) $p['file_id']),
            'backup' => BackupService::create((bool) ($p['include_media'] ?? false), 'queue'),
            'notify_favorites' => NotificationService::notifyFavorites((int) $p['game_id'], (string) $p['title'], (string) ($p['body'] ?? ''), (string) ($p['link'] ?? '')),
            default => throw new \RuntimeException('Unknown job type ' . $type),
        };
    }

    /** Puts jobs stuck in "running" (crashed worker) back into the queue. */
    public static function recoverStuck(int $minutes = 120): int
    {
        return DB::run("UPDATE jobs SET status = 'pending', reserve_token = NULL WHERE status = 'running' AND started_at < ?",
            [gmdate('Y-m-d H:i:s', time() - $minutes * 60)])->rowCount();
    }

    public static function stats(): array
    {
        $out = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
        foreach (DB::all('SELECT status, COUNT(*) c FROM jobs GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['c'];
        }
        return $out;
    }

    public static function recent(int $limit = 20): array
    {
        return DB::all('SELECT id, type, status, attempts, error, created_at, finished_at FROM jobs ORDER BY id DESC LIMIT ' . (int) $limit);
    }

    public static function prune(int $days = 14): int
    {
        return DB::run("DELETE FROM jobs WHERE status = 'done' AND finished_at < ?", [gmdate('Y-m-d H:i:s', time() - $days * 86400)])->rowCount();
    }
}
