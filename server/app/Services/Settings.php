<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\DB;

/** Runtime settings stored in the `settings` table, typed by their defaults below. */
final class Settings
{
    public const DEFAULTS = [
        'site.name' => 'ULTIMATE PS LIBRARY',
        'site.tagline' => 'مكتبة ألعاب PlayStation المتكاملة',
        'site.default_theme' => 'dark',
        'site.cdn_url' => '',
        'site.maintenance' => false,
        'site.maintenance_message' => 'المكتبة تحت الصيانة حالياً، نعود قريباً',
        'site.per_page' => 24,

        'auth.registration_open' => true,
        'auth.email_verification' => true,
        'auth.password_min' => 8,
        'auth.max_attempts' => 5,
        'auth.lockout_minutes' => 15,
        'auth.session_idle_minutes' => 120,

        'api.enabled' => true,
        'api.token_ttl_days' => 30,
        'api.rate_limit_per_minute' => 120,
        'api.cors_origins' => '',

        'uploads.chunk_size_mb' => 5,
        'uploads.max_file_gb' => 200,
        'uploads.allowed_extensions' => 'pkg,iso,bin,cue,chd,cso,pbp,img,ccd,sub,mdf,mds,zip,7z,rar,json,sfo,dat,txt,pdf,mp4,psv,psu,max,mcd,sav',
        'uploads.image_max_mb' => 15,
        'uploads.sync_assemble_limit_mb' => 2048,

        'downloads.link_ttl_minutes' => 360,
        'downloads.max_links_per_hour' => 60,

        'mail.driver' => 'log',
        'mail.host' => '',
        'mail.port' => 587,
        'mail.encryption' => 'tls',
        'mail.username' => '',
        'mail.password' => '',
        'mail.from_address' => 'no-reply@localhost',
        'mail.from_name' => 'ULTIMATE PS LIBRARY',

        'music.enabled' => true,
        'music.default_volume' => 35,
        'music.track' => '',
        'sfx.enabled' => true,

        'storage.default_disk' => 'local',

        'backups.frequency' => 'daily',
        'backups.keep' => 7,
        'backups.include_media' => false,
        'backups.last_run' => '',

        'updates.auto_on_publish' => true,
        'cron.last_run' => '',
    ];

    /** Values stored encrypted at rest. */
    public const SECRET = ['mail.password'];

    private static ?array $values = null;

    public static function all(): array
    {
        if (self::$values === null) {
            self::$values = Cache::remember(Cache::key('settings', 'all'), 600, static function () {
                $out = [];
                foreach (DB::all('SELECT `key`, `value` FROM settings') as $row) {
                    $out[$row['key']] = $row['value'];
                }
                return $out;
            }) ?? [];
        }
        return self::$values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $fallback = $default ?? (self::DEFAULTS[$key] ?? null);
        if (!Config::installed()) {
            return $fallback;
        }
        try {
            $all = self::all();
        } catch (\Throwable) {
            return $fallback;
        }
        if (!array_key_exists($key, $all) || $all[$key] === null) {
            return $fallback;
        }
        return self::cast($key, $all[$key]);
    }

    private static function cast(string $key, string $raw): mixed
    {
        $type = array_key_exists($key, self::DEFAULTS) ? get_debug_type(self::DEFAULTS[$key]) : 'string';
        return match ($type) {
            'bool' => in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true),
            'int' => (int) $raw,
            'float' => (float) $raw,
            'array' => json_decode($raw, true) ?: [],
            default => $raw,
        };
    }

    public static function set(string $key, mixed $value): void
    {
        if (in_array($key, self::SECRET, true) && is_string($value) && $value !== '') {
            $value = Crypto::encrypt($value);
        }
        $stored = match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
        DB::run(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)',
            [$key, $stored, gmdate('Y-m-d H:i:s')]
        );
        self::$values = null;
        Cache::bump('settings');
    }

    public static function setMany(array $values): void
    {
        foreach ($values as $k => $v) {
            self::set($k, $v);
        }
    }

    public static function forget(): void
    {
        self::$values = null;
    }
}
