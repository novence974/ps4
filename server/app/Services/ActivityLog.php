<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Logger;
use App\Core\Request;

final class ActivityLog
{
    public static function log(string $action, ?string $entityType = null, ?int $entityId = null, ?string $description = null, array $meta = [], ?int $userId = null): void
    {
        try {
            $r = Request::$current;
            DB::insert('activity_logs', [
                'user_id' => $userId ?? Auth::id(),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'description' => $description !== null ? mb_substr($description, 0, 500) : null,
                'ip' => $r?->ip(),
                'user_agent' => $r?->userAgent(),
                'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Activity log failed: ' . $e->getMessage(), ['action' => $action]);
        }
    }

    /** Groups shown as filters in the admin app and panel: group => action prefixes. */
    public const GROUPS = [
        'auth' => ['auth.'], 'users' => ['user.'], 'games' => ['game.', 'link.'], 'files' => ['file.', 'upload.'],
        'visitors' => ['visitor.', 'file.download', 'link.click'], 'catalog' => ['platform.', 'category.', 'update.', 'notification.'],
        'system' => ['settings.', 'backup.', 'cache.', 'storage.', 'roles.'], 'security' => ['security.', 'auth.failed', 'auth.locked'],
    ];

    /** Refused requests (403 forbidden, 419 expired session/CSRF, 429 rate limit), at most once per IP + path per 10 minutes. */
    public static function security(int $status, Request $req): void
    {
        try {
            $path = mb_substr((string) $req->path(), 0, 180);
            $cache = \App\Core\Cache::store();
            if ($cache->increment('seclog:' . $status . ':' . $req->ip() . ':' . md5($path), 600) > 1) {
                return;
            }
            // keep the log from filling the (small) database: at most 30 entries per address per hour, 500 in total
            if ($cache->increment('seclog-ip:' . $req->ip(), 3600) > 30 || $cache->increment('seclog-all', 3600) > 500) {
                return;
            }
            $action = [403 => 'security.forbidden', 419 => 'security.csrf', 429 => 'security.throttled'][$status];
            self::log($action, null, null, $req->method() . ' ' . $path, ['status' => $status]);
        } catch (\Throwable) {
        }
    }

    public static function groupOf(string $action): string
    {
        foreach (['security', 'visitors', 'auth', 'users', 'games', 'files', 'catalog', 'system'] as $g) {
            foreach (self::GROUPS[$g] as $prefix) {
                if (str_ends_with($prefix, '.') ? str_starts_with($action, $prefix) : $action === $prefix) {
                    return $g;
                }
            }
        }
        return 'other';
    }

    /** Short device description from a user agent: "Android • Chrome", "iPhone • Safari", "تطبيق الإدارة"… */
    public static function device(?string $ua): ?string
    {
        if (!$ua) {
            return null;
        }
        if (str_contains($ua, 'UPSL-Admin') || str_contains($ua, 'okhttp') || str_contains($ua, 'Dalvik')) {
            return 'تطبيق الإدارة';
        }
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'iPad') => 'iPad', str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'PlayStation') => 'PlayStation',
            str_contains($ua, 'Linux') => 'Linux', default => '',
        };
        $br = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'SamsungBrowser') => 'Samsung',
            str_contains($ua, 'Firefox/') => 'Firefox', str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'curl/') || str_contains($ua, 'python') || str_contains($ua, 'bot') => 'برنامج آلي', default => '',
        };
        return trim($os . ($os && $br ? ' • ' : '') . $br) ?: mb_substr($ua, 0, 40);
    }

    public static function summary(): array
    {
        $out = ['latest_id' => (int) DB::value('SELECT COALESCE(MAX(id), 0) FROM activity_logs'), 'groups' => []];
        $d1 = gmdate('Y-m-d H:i:s', time() - 86400);
        $d7 = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        $rows = DB::all('SELECT action, SUM(created_at >= :d1) AS day, COUNT(*) AS week FROM activity_logs WHERE created_at >= :d7 GROUP BY action', ['d1' => $d1, 'd7' => $d7]);
        foreach (array_keys(self::GROUPS) + [8 => 'other'] as $g) {
            $out['groups'][$g] = ['day' => 0, 'week' => 0];
        }
        foreach ($rows as $r) {
            $g = self::groupOf($r['action']);
            $out['groups'][$g]['day'] += (int) $r['day'];
            $out['groups'][$g]['week'] += (int) $r['week'];
        }
        $out['visitors_24h'] = (int) DB::value('SELECT COUNT(DISTINCT ip) FROM activity_logs WHERE created_at >= ?', [$d1]);
        return $out;
    }

    /** Deletes entries older than the retention setting (default 180 days); called hourly by bin/cron.php. */
    public static function prune(): int
    {
        $days = max(7, (int) Settings::get('logs.retention_days', 180));
        return DB::run('DELETE FROM activity_logs WHERE created_at < ? LIMIT 20000', [gmdate('Y-m-d H:i:s', time() - $days * 86400)])->rowCount();
    }

    public static function list(array $f, int $page = 1, int $perPage = 50): array
    {
        $where = ['1=1'];
        $p = [];
        if (!empty($f['group']) && isset(self::GROUPS[$f['group']])) {
            $or = [];
            foreach (self::GROUPS[$f['group']] as $i => $prefix) {
                $or[] = "a.action LIKE :g$i";
                $p["g$i"] = str_replace(['%', '_'], ['\%', '\_'], $prefix) . (str_ends_with($prefix, '.') ? '%' : '');
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        if (!empty($f['since_id'])) { $where[] = 'a.id > :since'; $p['since'] = (int) $f['since_id']; }
        if (!empty($f['ip'])) { $where[] = 'a.ip = :ip'; $p['ip'] = (string) $f['ip']; }
        if (!empty($f['to'])) { $where[] = 'a.created_at <= :to'; $p['to'] = $f['to'] . ' 23:59:59'; }
        if (!empty($f['user_id'])) { $where[] = 'a.user_id = :uid'; $p['uid'] = (int) $f['user_id']; }
        if (!empty($f['action'])) { $where[] = 'a.action LIKE :act'; $p['act'] = str_replace(['%', '_'], ['\%', '\_'], (string) $f['action']) . '%'; }
        if (!empty($f['q'])) { $where[] = '(a.description LIKE :q OR u.username LIKE :q2 OR a.ip = :qip)'; $p['q'] = '%' . $f['q'] . '%'; $p['q2'] = '%' . $f['q'] . '%'; $p['qip'] = (string) $f['q']; }
        if (!empty($f['from'])) { $where[] = 'a.created_at >= :from'; $p['from'] = $f['from'] . ' 00:00:00'; }
        $w = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $w", $p);
        $rows = DB::all(
            "SELECT a.*, u.username FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $w ORDER BY a.id DESC LIMIT :lim OFFSET :off",
            $p + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]
        );
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function label(string $action): string
    {
        return [
            'security.forbidden' => 'محاولة وصول مرفوضة', 'security.csrf' => 'طلب مرفوض (جلسة منتهية)', 'security.throttled' => 'طلبات كثيرة (حظر مؤقت)',
            'favorite.added' => 'إضافة للمفضلة', 'favorite.removed' => 'إزالة من المفضلة', 'link.click' => 'فتح رابط', 'game.viewed' => 'مشاهدة لعبة',
            'auth.login' => 'تسجيل دخول', 'auth.logout' => 'تسجيل خروج', 'auth.failed' => 'محاولة دخول فاشلة', 'auth.locked' => 'قفل حساب مؤقت',
            'auth.reset_requested' => 'طلب استعادة كلمة المرور', 'auth.password_reset' => 'إعادة تعيين كلمة المرور', 'auth.password_changed' => 'تغيير كلمة المرور',
            'auth.token_created' => 'إنشاء رمز API', 'auth.token_revoked' => 'إلغاء رمز API',
            'user.register' => 'تسجيل حساب', 'user.verified' => 'تأكيد البريد', 'user.updated' => 'تعديل مستخدم', 'user.deleted' => 'حذف مستخدم', 'user.profile' => 'تعديل الملف الشخصي',
            'game.created' => 'إضافة لعبة', 'game.updated' => 'تعديل لعبة', 'game.deleted' => 'حذف لعبة', 'game.published' => 'نشر لعبة', 'game.hidden' => 'إخفاء لعبة',
            'game.media' => 'تحديث وسائط لعبة', 'game.import' => 'استيراد بالجملة',
            'file.created' => 'إضافة ملف', 'file.updated' => 'تعديل ملف', 'file.deleted' => 'حذف ملف', 'file.verified' => 'تحقق من ملف', 'file.download' => 'تنزيل ملف',
            'upload.completed' => 'اكتمال رفع', 'upload.failed' => 'فشل رفع', 'upload.cancelled' => 'إلغاء رفع',
            'platform.updated' => 'تعديل جهاز', 'platform.created' => 'إضافة جهاز', 'category.saved' => 'حفظ تصنيف', 'category.deleted' => 'حذف تصنيف',
            'update.saved' => 'حفظ تحديث', 'update.deleted' => 'حذف تحديث', 'notification.sent' => 'إرسال إشعار',
            'settings.updated' => 'تعديل الإعدادات', 'backup.created' => 'نسخة احتياطية', 'backup.restored' => 'استعادة نسخة', 'backup.deleted' => 'حذف نسخة',
            'storage.cleanup' => 'تنظيف التخزين', 'roles.updated' => 'تعديل الصلاحيات', 'cache.cleared' => 'مسح الكاش',
        ][$action] ?? $action;
    }
}
