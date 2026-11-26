<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;

/** Converts database rows into the stable JSON shapes of API v1 (absolute URLs, typed values, ISO dates). */
final class Presenter
{
    private static function int(mixed $v): ?int { return $v === null ? null : (int) $v; }
    private static function float(mixed $v): ?float { return $v === null ? null : (float) $v; }

    public static function platformRef(array $g): array
    {
        return ['id' => (int) $g['platform_id'], 'slug' => $g['platform_slug'], 'name' => $g['platform_name'] ?? null,
            'short_name' => $g['platform_short'] ?? null, 'color' => $g['platform_color'] ?? null];
    }

    public static function gameSummary(array $g, array $favoriteIds = []): array
    {
        return [
            'id' => (int) $g['id'],
            'title' => $g['title'],
            'slug' => $g['slug'],
            'platform' => self::platformRef($g),
            'product_code' => $g['product_code'] ?? null,
            'release_year' => self::int($g['release_year'] ?? null),
            'developer' => $g['developer'] ?? null,
            'publisher' => $g['publisher'] ?? null,
            'age_rating' => $g['age_rating'] ?? null,
            'size_bytes' => self::int($g['size_bytes'] ?? null),
            'size_human' => isset($g['size_bytes']) ? human_bytes((int) $g['size_bytes']) : null,
            'languages' => !empty($g['languages']) ? explode(',', (string) $g['languages']) : [],
            'region' => $g['region'] ?? null,
            'version' => $g['version'] ?? null,
            'edition' => $g['edition'] ?? null,
            'rating' => self::float($g['rating'] ?? null),
            'files_status' => $g['files_status'] ?? 'unavailable',
            'is_published' => (bool) ($g['is_published'] ?? false),
            'is_featured' => (bool) ($g['is_featured'] ?? false),
            'is_favorite' => in_array((int) $g['id'], $favoriteIds, true),
            'downloads' => (int) ($g['download_count'] ?? 0),
            'views' => (int) ($g['view_count'] ?? 0),
            'favorites' => (int) ($g['favorite_count'] ?? 0),
            'categories' => array_map(static fn ($c) => ['id' => (int) $c['id'], 'slug' => $c['slug'], 'name' => $c['name']], $g['categories'] ?? []),
            'cover' => MediaService::urls($g['cover_path'] ?? null, 'cover'),
            'url' => abs_url('game/' . $g['slug']),
            'created_at' => iso_date($g['created_at'] ?? null),
            'updated_at' => iso_date($g['updated_at'] ?? null),
            'published_at' => iso_date($g['published_at'] ?? null),
        ];
    }

    public static function gameDetail(array $g, ?array $user = null, bool $admin = false): array
    {
        $fav = $user ? GameService::favoriteIds((int) $user['id'], [(int) $g['id']]) : [];
        $out = self::gameSummary($g, $fav);
        $embed = GameService::trailerEmbed($g['trailer_url'] ?? null);
        $trailerUrl = !empty($g['trailer_url']) ? (str_starts_with((string) $g['trailer_url'], 'media/') ? abs_url((string) $g['trailer_url']) : (string) $g['trailer_url']) : null;
        $out += [
            'description' => $g['description'],
            'compatibility_notes' => $g['compatibility_notes'],
            'banner' => MediaService::urls($g['banner_path'] ?? null, 'banner'),
            'trailer' => $embed ? ['type' => $embed['type'], 'url' => $trailerUrl, 'embed' => $embed['type'] === 'video' ? $trailerUrl : $embed['src']] : null,
            'screenshots' => array_map(static fn ($i) => ['id' => (int) $i['id'], 'caption' => $i['caption'], 'width' => self::int($i['width']), 'height' => self::int($i['height'])] + (MediaService::urls($i['path'], 'screenshot') ?? []), $g['images'] ?? []),
            'versions' => array_map([self::class, 'version'], $g['versions'] ?? []),
            'files' => array_map(static fn ($f) => self::file($f + ['game_published' => $g['is_published']], $user, $admin), $g['files'] ?? []),
            'links' => array_map([self::class, 'link'], $g['links'] ?? []),
            'compatibility' => array_map(static fn ($c) => ['id' => (int) $c['id'], 'target' => $c['target'], 'status' => $c['status'], 'status_label' => compat_names()[$c['status']] ?? $c['status'], 'notes' => $c['notes'], 'tested_at' => $c['tested_at']], $g['compat'] ?? []),
            'platform_info' => [
                'supported_formats' => $g['platform_formats'] ?? null,
                'compatibility_notes' => $g['platform_compat'] ?? null,
                'install_notes' => $g['platform_install'] ?? null,
            ],
        ];
        return $out;
    }

    public static function version(array $v): array
    {
        return ['id' => (int) $v['id'], 'version' => $v['version'], 'region' => $v['region'], 'release_date' => $v['release_date'],
            'size_bytes' => self::int($v['size_bytes']), 'changelog' => $v['changelog'], 'is_latest' => (bool) $v['is_latest'],
            'created_at' => iso_date($v['created_at'])];
    }

    public static function link(array $l): array
    {
        return ['id' => (int) $l['id'], 'title' => $l['title'], 'url' => $l['url'], 'type' => $l['link_type'],
            'type_label' => link_type_names()[$l['link_type']] ?? $l['link_type'], 'region' => $l['region'], 'is_active' => (bool) $l['is_active'], 'sort_order' => (int) $l['sort_order']];
    }

    public static function file(array $f, ?array $user = null, bool $admin = false): array
    {
        $reason = FileService::denyReason($user, $f);
        $out = [
            'id' => (int) $f['id'],
            'type' => $f['file_type'],
            'type_label' => file_type_names()[$f['file_type']] ?? $f['file_type'],
            'label' => $f['label'],
            'name' => $f['original_name'],
            'size_bytes' => (int) $f['size_bytes'],
            'size_human' => human_bytes((int) $f['size_bytes']),
            'sha256' => $f['sha256'],
            'region' => $f['region'],
            'version' => $f['version_label'] ?: ($f['version_name'] ?? null),
            'access_level' => $f['access_level'],
            'can_download' => $reason === null,
            'deny_reason' => $reason,
            'downloads' => (int) $f['download_count'],
            'created_at' => iso_date($f['created_at']),
        ];
        if ($admin) {
            $out += ['is_active' => (bool) $f['is_active'], 'disk' => $f['disk'], 'mime' => $f['mime'], 'version_id' => self::int($f['version_id']), 'verified_at' => iso_date($f['verified_at'])];
        }
        return $out;
    }

    public static function platform(array $p): array
    {
        return [
            'id' => (int) $p['id'], 'slug' => $p['slug'], 'name' => $p['name'], 'short_name' => $p['short_name'],
            'manufacturer' => $p['manufacturer'], 'release_year' => self::int($p['release_year']), 'generation' => self::int($p['generation']),
            'description' => $p['description'], 'color' => $p['accent_color'], 'games_count' => (int) ($p['games_count'] ?? 0),
            'logo' => MediaService::urls($p['logo_path'], 'platform_logo'), 'image' => MediaService::urls($p['image_path'], 'platform_image'),
            'supported_formats' => $p['supported_formats'], 'compatibility_notes' => $p['compatibility_notes'], 'install_notes' => $p['install_notes'],
            'is_active' => (bool) $p['is_active'], 'sort_order' => (int) $p['sort_order'],
        ];
    }

    public static function category(array $c): array
    {
        return ['id' => (int) $c['id'], 'slug' => $c['slug'], 'name' => $c['name'], 'name_en' => $c['name_en'], 'icon' => $c['icon'],
            'color' => $c['color'], 'games_count' => (int) ($c['games_count'] ?? 0), 'sort_order' => (int) $c['sort_order']];
    }

    public static function user(array $u, bool $private = true): array
    {
        $out = [
            'id' => (int) $u['id'], 'username' => $u['username'], 'display_name' => $u['display_name'] ?: $u['username'],
            'avatar' => MediaService::urls($u['avatar_path'] ?? null, 'avatar'),
            'role' => ['id' => self::int($u['role_id'] ?? null), 'slug' => $u['role_slug'] ?? null, 'name' => $u['role_name'] ?? null, 'level' => self::int($u['role_level'] ?? null)],
            'created_at' => iso_date($u['created_at']),
        ];
        if ($private) {
            $out += [
                'email' => $u['email'], 'bio' => $u['bio'] ?? null, 'email_verified' => !empty($u['email_verified_at']),
                'status' => $u['status'], 'can_download' => (bool) $u['can_download'],
                'last_login_at' => iso_date($u['last_login_at'] ?? null),
                'preferences' => json_decode((string) ($u['preferences'] ?? ''), true) ?: new \stdClass(),
            ];
            if (Auth::id() === (int) $u['id']) {
                $out['permissions'] = $u['role_slug'] === 'super_admin' ? ['*'] : Auth::permissions();
            }
        }
        return $out;
    }

    public static function update(array $u): array
    {
        return [
            'id' => (int) $u['id'], 'type' => $u['type'], 'type_label' => update_type_names()[$u['type']] ?? $u['type'],
            'title' => $u['title'], 'body' => $u['body'], 'is_published' => (bool) $u['is_published'],
            'game' => $u['game_id'] ? ['id' => (int) $u['game_id'], 'title' => $u['game_title'], 'slug' => $u['game_slug'], 'cover' => MediaService::urls($u['game_cover'] ?? null, 'cover')] : null,
            'platform' => $u['platform_short'] ?? null, 'version' => $u['version_name'] ?? null,
            'published_at' => iso_date($u['published_at'] ?? $u['created_at']), 'created_at' => iso_date($u['created_at']),
        ];
    }

    public static function notification(array $n): array
    {
        return ['id' => (int) $n['id'], 'type' => $n['type'], 'title' => $n['title'], 'body' => $n['body'],
            'link' => $n['link'] ? (str_starts_with($n['link'], '/') ? abs_url($n['link']) : $n['link']) : null,
            'broadcast' => $n['user_id'] === null, 'is_read' => (bool) ($n['is_read'] ?? $n['read_at'] !== null), 'created_at' => iso_date($n['created_at'])];
    }
}
