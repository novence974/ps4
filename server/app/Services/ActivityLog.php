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

    public static function list(array $f, int $page = 1, int $perPage = 50): array
    {
        $where = ['1=1'];
        $p = [];
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
