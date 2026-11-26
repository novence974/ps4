<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Validator;

/** Library updates feed: new games, game updates, patches, announcements, maintenance notices. */
final class UpdateService
{
    public const TYPES = ['game_added', 'game_updated', 'patch', 'announcement', 'maintenance'];

    /** Set during bulk imports so the feed is not flooded with one entry per game. */
    public static bool $suppressAuto = false;

    private const COLUMNS = 'u.*, g.title AS game_title, g.slug AS game_slug, g.cover_path AS game_cover, p.short_name AS platform_short, v.version AS version_name';
    private const JOINS = 'LEFT JOIN games g ON g.id = u.game_id LEFT JOIN platforms p ON p.id = COALESCE(u.platform_id, g.platform_id) LEFT JOIN game_versions v ON v.id = u.version_id';

    public static function list(int $page = 1, int $perPage = 20, bool $admin = false, ?string $type = null): array
    {
        $w = [];
        $p = [];
        if (!$admin) {
            $w[] = 'u.is_published = 1 AND (u.game_id IS NULL OR g.is_published = 1)';
        }
        if ($type && in_array($type, self::TYPES, true)) {
            $w[] = 'u.type = :t';
            $p['t'] = $type;
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        $key = Cache::key('games', 'updates:' . md5(json_encode([$page, $perPage, $admin, $type])));
        if (!$admin && is_array($c = Cache::get($key))) {
            return $c;
        }
        $total = (int) DB::value('SELECT COUNT(*) FROM updates u ' . self::JOINS . " $where", $p);
        $rows = DB::all('SELECT ' . self::COLUMNS . ' FROM updates u ' . self::JOINS . " $where ORDER BY COALESCE(u.published_at, u.created_at) DESC, u.id DESC LIMIT :lim OFFSET :off",
            $p + ['lim' => $perPage, 'off' => (max(1, $page) - 1) * $perPage]);
        $out = ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
        if (!$admin) {
            Cache::set($key, $out, 120);
        }
        return $out;
    }

    /** "سجل التحديثات" for a user: updates of favourite games + general announcements. */
    public static function forUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $where = 'u.is_published = 1 AND ((u.game_id IS NULL) OR (g.is_published = 1 AND u.game_id IN (SELECT game_id FROM favorites WHERE user_id = :uid)))';
        $total = (int) DB::value('SELECT COUNT(*) FROM updates u ' . self::JOINS . " WHERE $where", ['uid' => $userId]);
        $rows = DB::all('SELECT ' . self::COLUMNS . ' FROM updates u ' . self::JOINS . " WHERE $where ORDER BY COALESCE(u.published_at, u.created_at) DESC LIMIT :lim OFFSET :off",
            ['uid' => $userId, 'lim' => $perPage, 'off' => (max(1, $page) - 1) * $perPage]);
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function find(int $id): ?array
    {
        return DB::one('SELECT ' . self::COLUMNS . ' FROM updates u ' . self::JOINS . ' WHERE u.id = ?', [$id]);
    }

    public static function save(?int $id, array $input): int
    {
        $d = Validator::validate($input, [
            'type' => 'required|in:' . implode(',', self::TYPES),
            'title' => 'required|string|max:190',
            'body' => 'nullable|string|max:10000',
            'game_id' => 'nullable|int|exists:games,id',
            'platform_id' => 'nullable|int|exists:platforms,id',
            'version_id' => 'nullable|int',
            'is_published' => 'bool',
            'notify_favorites' => 'bool',
        ], ['type' => 'النوع', 'title' => 'العنوان', 'body' => 'التفاصيل', 'game_id' => 'اللعبة', 'platform_id' => 'الجهاز']);
        $row = [
            'type' => $d['type'], 'title' => $d['title'], 'body' => $d['body'] ?: null,
            'game_id' => $d['game_id'] ?: null, 'platform_id' => $d['platform_id'] ?: null,
            'version_id' => (!empty($d['version_id']) && !empty($d['game_id']) && DB::value('SELECT 1 FROM game_versions WHERE id = ? AND game_id = ?', [$d['version_id'], $d['game_id']])) ? (int) $d['version_id'] : null,
            'is_published' => $d['is_published'] ? 1 : 0, 'updated_at' => now(),
        ];
        $wasPublished = false;
        if ($id) {
            $old = DB::one('SELECT * FROM updates WHERE id = ?', [$id]) ?? throw new HttpException(404, 'التحديث غير موجود');
            $wasPublished = (bool) $old['is_published'];
            if ($row['is_published'] && !$old['published_at']) {
                $row['published_at'] = now();
            }
            DB::update('updates', $row, 'id = ?', [$id]);
        } else {
            $row += ['created_by' => Auth::id(), 'created_at' => now(), 'published_at' => $row['is_published'] ? now() : null];
            $id = DB::insert('updates', $row);
        }
        if ($row['is_published'] && !$wasPublished && $d['notify_favorites'] && $row['game_id']) {
            $g = DB::one('SELECT slug FROM games WHERE id = ?', [$row['game_id']]);
            NotificationService::notifyFavorites((int) $row['game_id'], $d['title'], (string) ($d['body'] ?? ''), '/game/' . ($g['slug'] ?? ''));
        }
        Cache::bump('games');
        ActivityLog::log('update.saved', 'update', $id, 'تحديث: ' . $d['title']);
        return $id;
    }

    public static function delete(int $id): void
    {
        if (!DB::delete('updates', 'id = ?', [$id])) {
            throw new HttpException(404, 'التحديث غير موجود');
        }
        Cache::bump('games');
        ActivityLog::log('update.deleted', 'update', $id, 'حذف تحديث');
    }

    public static function autoForGame(int $gameId, string $type): void
    {
        if (self::$suppressAuto || !Settings::get('updates.auto_on_publish', true)) {
            return;
        }
        $g = DB::one('SELECT g.title, g.platform_id, p.short_name FROM games g JOIN platforms p ON p.id = g.platform_id WHERE g.id = ?', [$gameId]);
        if (!$g) {
            return;
        }
        DB::insert('updates', [
            'type' => $type, 'game_id' => $gameId, 'platform_id' => (int) $g['platform_id'],
            'title' => ($type === 'game_added' ? 'أضيفت إلى المكتبة: ' : 'تحديث: ') . $g['title'] . ' (' . $g['short_name'] . ')',
            'body' => null, 'is_published' => 1, 'published_at' => now(), 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Cache::bump('games');
    }
}
