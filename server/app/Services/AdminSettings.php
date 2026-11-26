<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\ValidationException;

/**
 * Editable settings groups shared by the admin panel and the admin API (mobile app).
 * Each group has its own permission and a schema: key => [type, options].
 */
final class AdminSettings
{
    public static function groups(): array
    {
        return [
            'general' => ['perm' => 'settings.manage', 'schema' => [
                'site.name' => ['string', 80], 'site.tagline' => ['string', 160], 'site.default_theme' => ['enum', ['dark', 'light', 'auto']],
                'site.cdn_url' => ['url'], 'site.per_page' => ['int', [6, 100]], 'site.maintenance' => ['bool'], 'site.maintenance_message' => ['string', 300],
                'updates.auto_on_publish' => ['bool'], 'music.enabled' => ['bool'], 'music.default_volume' => ['int', [0, 100]], 'sfx.enabled' => ['bool'],
                'mail.driver' => ['enum', ['log', 'smtp', 'mail']], 'mail.host' => ['string', 190], 'mail.port' => ['int', [1, 65535]],
                'mail.encryption' => ['enum', ['tls', 'ssl', 'none']], 'mail.username' => ['string', 190], 'mail.password' => ['secret'],
                'mail.from_address' => ['email'], 'mail.from_name' => ['string', 120],
            ]],
            'security' => ['perm' => 'security.manage', 'schema' => [
                'auth.registration_open' => ['bool'], 'auth.email_verification' => ['bool'],
                'auth.password_min' => ['int', [8, 128]], 'auth.max_attempts' => ['int', [3, 50]], 'auth.lockout_minutes' => ['int', [1, 1440]],
                'auth.session_idle_minutes' => ['int', [5, 10080]],
                'uploads.allowed_extensions' => ['string', 1000], 'uploads.max_file_gb' => ['int', [1, 2048]], 'uploads.image_max_mb' => ['int', [1, 100]],
                'uploads.chunk_size_mb' => ['int', [1, 64]], 'uploads.sync_assemble_limit_mb' => ['int', [16, 1048576]],
                'downloads.link_ttl_minutes' => ['int', [5, 10080]], 'downloads.max_links_per_hour' => ['int', [1, 100000]],
                'storage.default_disk' => ['enum', array_keys(FileService::disks())],
            ]],
            'api' => ['perm' => 'api.manage', 'schema' => [
                'api.enabled' => ['bool'], 'api.token_ttl_days' => ['int', [1, 3650]], 'api.rate_limit_per_minute' => ['int', [10, 100000]],
                'api.cors_origins' => ['string', 1000],
            ]],
            'backups' => ['perm' => 'backups.manage', 'schema' => [
                'backups.frequency' => ['enum', ['off', 'daily', 'weekly']], 'backups.keep' => ['int', [1, 365]], 'backups.include_media' => ['bool'],
            ]],
        ];
    }

    public static function group(string $name): array
    {
        $g = self::groups()[$name] ?? null;
        if (!$g) {
            throw new HttpException(404, 'مجموعة الإعدادات غير موجودة');
        }
        return $g;
    }

    /** Groups the current user may see, with values (secrets are never returned, only whether they are set) and field rules. */
    public static function readable(): array
    {
        $out = [];
        foreach (self::groups() as $name => $g) {
            if (!Auth::can($g['perm'])) {
                continue;
            }
            $fields = [];
            foreach ($g['schema'] as $key => $def) {
                $value = Settings::get($key);
                $field = ['key' => $key, 'type' => $def[0]];
                if ($def[0] === 'secret') {
                    $field['is_set'] = is_string($value) && $value !== '';
                    $field['value'] = null;
                } else {
                    $field['value'] = $value;
                }
                if ($def[0] === 'int') {
                    [$field['min'], $field['max']] = $def[1];
                } elseif ($def[0] === 'enum') {
                    $field['options'] = array_values($def[1]);
                } elseif ($def[0] === 'string') {
                    $field['max_length'] = $def[1] ?? 255;
                }
                $fields[] = $field;
            }
            $out[$name] = $fields;
        }
        return $out;
    }

    /**
     * Validates and saves a group.
     * $partial = true (API): only keys present in $input are touched. Keys may be written "site.name" or "site_name".
     * $partial = false (web form): every key of the schema is read (absent booleans mean false).
     *
     * @return array<string,mixed> saved values (secrets masked)
     */
    public static function save(string $groupName, array $input, bool $partial): array
    {
        $g = self::group($groupName);
        if (!Auth::can($g['perm'])) {
            throw new HttpException(403);
        }
        $errors = [];
        $values = [];
        foreach ($g['schema'] as $key => $def) {
            $field = str_replace('.', '_', $key);
            $present = array_key_exists($key, $input) || array_key_exists($field, $input);
            if ($partial && !$present) {
                continue;
            }
            $raw = $input[$key] ?? $input[$field] ?? null;
            switch ($def[0]) {
                case 'bool':
                    $values[$key] = $raw === true || in_array(strtolower((string) $raw), ['1', 'on', 'true', 'yes'], true);
                    break;
                case 'int':
                    [$min, $max] = $def[1];
                    if (!is_numeric($raw) || (int) $raw < $min || (int) $raw > $max) {
                        $errors[$field] = "القيمة يجب أن تكون بين {$min} و {$max}";
                    } else {
                        $values[$key] = (int) $raw;
                    }
                    break;
                case 'enum':
                    if (!is_scalar($raw) || !in_array((string) $raw, $def[1], true)) {
                        $errors[$field] = 'قيمة غير مسموحة';
                    } else {
                        $values[$key] = (string) $raw;
                    }
                    break;
                case 'secret':
                    if (is_string($raw) && $raw !== '') {
                        $values[$key] = $raw;
                    }
                    break;
                case 'url':
                    $raw = trim((string) $raw);
                    if ($raw !== '' && (!filter_var($raw, FILTER_VALIDATE_URL) || !preg_match('#^https?://#', $raw))) {
                        $errors[$field] = 'رابط غير صحيح';
                    } else {
                        $values[$key] = rtrim($raw, '/');
                    }
                    break;
                case 'email':
                    if (!filter_var((string) $raw, FILTER_VALIDATE_EMAIL)) {
                        $errors[$field] = 'بريد غير صحيح';
                    } else {
                        $values[$key] = (string) $raw;
                    }
                    break;
                default:
                    if ($raw !== null && !is_scalar($raw)) {
                        $errors[$field] = 'قيمة غير صحيحة';
                        break;
                    }
                    $values[$key] = mb_substr(trim(strip_tags((string) $raw)), 0, $def[1] ?? 255);
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        if ($values) {
            Settings::setMany($values);
            ActivityLog::log('settings.updated', 'settings', null, 'تعديل الإعدادات: ' . implode(', ', array_keys($values)));
        }
        foreach ($g['schema'] as $key => $def) {
            if ($def[0] === 'secret' && array_key_exists($key, $values)) {
                $values[$key] = '••••';
            }
        }
        return $values;
    }
}
