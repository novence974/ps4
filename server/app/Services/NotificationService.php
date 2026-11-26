<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Validator;

/** Per-user notifications plus broadcasts (user_id NULL, read state via users.notifications_seen_at). */
final class NotificationService
{
    public static function toUser(int $userId, string $type, string $title, ?string $body = null, ?string $link = null, ?int $by = null): int
    {
        return DB::insert('notifications', [
            'user_id' => $userId, 'type' => mb_substr($type, 0, 32), 'title' => mb_substr($title, 0, 190),
            'body' => $body !== null ? mb_substr($body, 0, 1000) : null, 'link' => $link, 'created_by' => $by, 'created_at' => now(),
        ]);
    }

    public static function broadcast(string $type, string $title, ?string $body = null, ?string $link = null, ?int $by = null): int
    {
        return DB::insert('notifications', [
            'user_id' => null, 'type' => mb_substr($type, 0, 32), 'title' => mb_substr($title, 0, 190),
            'body' => $body !== null ? mb_substr($body, 0, 1000) : null, 'link' => $link, 'created_by' => $by, 'created_at' => now(),
        ]);
    }

    /** One INSERT…SELECT, scales to any number of followers. */
    public static function notifyFavorites(int $gameId, string $title, string $body = '', string $link = ''): int
    {
        return DB::run('INSERT INTO notifications (user_id, type, title, body, link, created_at)
                        SELECT f.user_id, ?, ?, ?, ?, ? FROM favorites f WHERE f.game_id = ?',
            ['game_update', mb_substr($title, 0, 190), mb_substr($body, 0, 1000) ?: null, $link ?: null, now(), $gameId])->rowCount();
    }

    /** Admin form / API: send to one user (username or id) or to everyone. */
    public static function send(array $input, int $by): int
    {
        $d = Validator::validate($input, [
            'title' => 'required|string|max:190', 'body' => 'nullable|string|max:1000',
            'link' => 'nullable|string|max:500', 'audience' => 'required|in:all,user', 'user' => 'nullable|string|max:190',
        ], ['title' => 'العنوان', 'body' => 'النص', 'audience' => 'المستلمون', 'user' => 'المستخدم']);
        if ($d['link'] && !preg_match('#^(/|https?://)#', $d['link'])) {
            throw \App\Core\ValidationException::single('link', 'الرابط يجب أن يبدأ بـ / أو https://');
        }
        if ($d['audience'] === 'user') {
            $u = DB::one('SELECT id FROM users WHERE id = ? OR username = ? OR email = ? LIMIT 1', [ctype_digit((string) $d['user']) ? (int) $d['user'] : 0, (string) $d['user'], (string) $d['user']]);
            if (!$u) {
                throw \App\Core\ValidationException::single('user', 'المستخدم غير موجود');
            }
            $id = self::toUser((int) $u['id'], 'admin', $d['title'], $d['body'], $d['link'], $by);
        } else {
            $id = self::broadcast('admin', $d['title'], $d['body'], $d['link'], $by);
        }
        ActivityLog::log('notification.sent', 'notification', $id, 'إشعار: ' . $d['title']);
        return $id;
    }

    public static function forUser(array $user, int $page = 1, int $perPage = 30): array
    {
        $seen = $user['notifications_seen_at'] ?: $user['created_at'];
        $p = ['uid' => (int) $user['id'], 'since' => $user['created_at']];
        $where = '(n.user_id = :uid OR (n.user_id IS NULL AND n.created_at >= :since))';
        $total = (int) DB::value("SELECT COUNT(*) FROM notifications n WHERE $where", $p);
        $rows = DB::all("SELECT n.* FROM notifications n WHERE $where ORDER BY n.id DESC LIMIT :lim OFFSET :off",
            $p + ['lim' => $perPage, 'off' => (max(1, $page) - 1) * $perPage]);
        foreach ($rows as &$r) {
            $r['is_read'] = $r['user_id'] !== null ? $r['read_at'] !== null : ($r['created_at'] <= $seen);
        }
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage)), 'unread' => self::unreadCount($user)];
    }

    public static function unreadCount(array $user): int
    {
        return (int) DB::value('SELECT
            (SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND read_at IS NULL) +
            (SELECT COUNT(*) FROM notifications WHERE user_id IS NULL AND created_at > :seen AND created_at >= :since)',
            ['uid' => (int) $user['id'], 'seen' => $user['notifications_seen_at'] ?: $user['created_at'], 'since' => $user['created_at']]);
    }

    public static function markRead(array $user, ?int $id = null): void
    {
        if ($id === null) {
            DB::run('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [now(), (int) $user['id']]);
            DB::update('users', ['notifications_seen_at' => now()], 'id = ?', [(int) $user['id']]);
            return;
        }
        $n = DB::one('SELECT * FROM notifications WHERE id = ? AND (user_id = ? OR user_id IS NULL)', [$id, (int) $user['id']]);
        if (!$n) {
            return;
        }
        if ($n['user_id'] !== null) {
            DB::update('notifications', ['read_at' => now()], 'id = ?', [$id]);
        } elseif (!$user['notifications_seen_at'] || $n['created_at'] > $user['notifications_seen_at']) {
            DB::update('users', ['notifications_seen_at' => $n['created_at']], 'id = ?', [(int) $user['id']]);
        }
    }

    public static function adminList(int $page = 1, int $perPage = 30): array
    {
        $total = (int) DB::value('SELECT COUNT(*) FROM notifications WHERE created_by IS NOT NULL');
        $rows = DB::all('SELECT n.*, u.username AS target, b.username AS sender FROM notifications n
                LEFT JOIN users u ON u.id = n.user_id LEFT JOIN users b ON b.id = n.created_by
               WHERE n.created_by IS NOT NULL ORDER BY n.id DESC LIMIT :lim OFFSET :off',
            ['lim' => $perPage, 'off' => (max(1, $page) - 1) * $perPage]);
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function delete(int $id): void
    {
        DB::delete('notifications', 'id = ?', [$id]);
    }
}
