<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Validator;

final class UserService
{
    public static function roles(): array
    {
        return DB::all('SELECT * FROM roles ORDER BY level DESC');
    }

    public static function profileStats(int $userId): array
    {
        return DB::one('SELECT
            (SELECT COUNT(*) FROM favorites WHERE user_id = :a) AS favorites,
            (SELECT COUNT(*) FROM downloads WHERE user_id = :b) AS downloads,
            (SELECT COUNT(*) FROM view_history WHERE user_id = :c) AS viewed', ['a' => $userId, 'b' => $userId, 'c' => $userId]) ?? [];
    }

    public static function updateProfile(array $user, array $input): array
    {
        $uid = (int) $user['id'];
        $d = Validator::validate($input, [
            'display_name' => 'nullable|string|max:64',
            'bio' => 'nullable|string|max:500',
            'email' => 'required|email|max:190|unique:users,email,' . $uid,
        ], ['display_name' => 'الاسم الظاهر', 'bio' => 'نبذة', 'email' => 'البريد الإلكتروني'], true);
        $row = [];
        foreach (['display_name', 'bio'] as $k) {
            if (array_key_exists($k, $d)) {
                $row[$k] = $d[$k] !== null ? strip_tags((string) $d[$k]) : null;
            }
        }
        $emailChanged = isset($d['email']) && $d['email'] !== $user['email'];
        if ($emailChanged) {
            $row['email'] = $d['email'];
            if (Settings::get('auth.email_verification', true)) {
                $row['email_verified_at'] = null;
            }
        }
        if ($row) {
            $row['updated_at'] = now();
            DB::update('users', $row, 'id = ?', [$uid]);
        }
        $fresh = Auth::loadUser($uid);
        if ($emailChanged && Settings::get('auth.email_verification', true)) {
            AuthService::sendVerification($fresh);
        }
        ActivityLog::log('user.profile', 'user', $uid, 'تعديل الملف الشخصي');
        return $fresh;
    }

    public static function setAvatar(int $userId, string $src): array
    {
        $u = DB::one('SELECT avatar_path FROM users WHERE id = ?', [$userId]) ?? throw new HttpException(404, 'المستخدم غير موجود');
        $stored = MediaService::storeImage($src, 'avatar');
        MediaService::deleteImage($u['avatar_path']);
        DB::update('users', ['avatar_path' => $stored['path'], 'updated_at' => now()], 'id = ?', [$userId]);
        return ['avatar' => MediaService::urls($stored['path'], 'avatar')];
    }

    public static function removeAvatar(int $userId): void
    {
        $u = DB::one('SELECT avatar_path FROM users WHERE id = ?', [$userId]);
        MediaService::deleteImage($u['avatar_path'] ?? null);
        DB::update('users', ['avatar_path' => null, 'updated_at' => now()], 'id = ?', [$userId]);
    }

    public static function savePreferences(int $userId, array $prefs): array
    {
        $current = json_decode((string) DB::value('SELECT preferences FROM users WHERE id = ?', [$userId]), true) ?: [];
        $allowed = [
            'theme' => static fn ($v) => in_array($v, ['dark', 'light', 'auto'], true) ? $v : null,
            'music' => static fn ($v) => (bool) $v,
            'sfx' => static fn ($v) => (bool) $v,
            'volume' => static fn ($v) => max(0, min(100, (int) $v)),
        ];
        foreach ($allowed as $k => $fn) {
            if (array_key_exists($k, $prefs)) {
                $val = $fn($prefs[$k]);
                if ($val !== null) {
                    $current[$k] = $val;
                }
            }
        }
        DB::update('users', ['preferences' => json_encode($current)], 'id = ?', [$userId]);
        return $current;
    }

    // ------------------------------------------------------------------ administration

    public static function list(array $f, int $page = 1, int $perPage = 30): array
    {
        $w = ['1=1'];
        $p = [];
        if (!empty($f['q'])) {
            $w[] = '(u.username LIKE :q1 OR u.email LIKE :q2 OR u.display_name LIKE :q3)';
            $p['q1'] = $p['q2'] = $p['q3'] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $f['q']) . '%';
        }
        if (!empty($f['role'])) { $w[] = 'r.slug = :role'; $p['role'] = (string) $f['role']; }
        if (!empty($f['status'])) { $w[] = 'u.status = :st'; $p['st'] = (string) $f['status']; }
        if (isset($f['verified']) && $f['verified'] !== '') { $w[] = $f['verified'] ? 'u.email_verified_at IS NOT NULL' : 'u.email_verified_at IS NULL'; }
        $where = implode(' AND ', $w);
        $total = (int) DB::value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE $where", $p);
        $rows = DB::all("SELECT u.id, u.username, u.email, u.display_name, u.avatar_path, u.status, u.can_download, u.email_verified_at,
                    u.last_login_at, u.last_login_ip, u.locked_until, u.created_at, u.role_id, r.slug AS role_slug, r.name AS role_name, r.level AS role_level,
                    (SELECT COUNT(*) FROM downloads d WHERE d.user_id = u.id) AS downloads_count
               FROM users u JOIN roles r ON r.id = u.role_id WHERE $where ORDER BY u.id DESC LIMIT :lim OFFSET :off",
            $p + ['lim' => $perPage, 'off' => (max(1, $page) - 1) * $perPage]);
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    private static function guard(array $actor, array $target): void
    {
        if ((int) $actor['id'] === (int) $target['id']) {
            throw new HttpException(403, 'لا يمكنك تعديل دورك أو حالتك أو حذف حسابك من لوحة الإدارة');
        }
        if ($actor['role_slug'] !== 'super_admin' && (int) $target['role_level'] >= (int) $actor['role_level']) {
            throw new HttpException(403, 'لا يمكنك إدارة حساب بمستوى صلاحيات مساوٍ لك أو أعلى');
        }
    }

    public static function adminUpdate(array $actor, int $id, array $input): array
    {
        $target = Auth::loadUser($id) ?? throw new HttpException(404, 'المستخدم غير موجود');
        self::guard($actor, $target);
        $d = Validator::validate($input, [
            'role_id' => 'nullable|int|exists:roles,id',
            'status' => 'nullable|in:active,suspended',
            'can_download' => 'bool',
            'email_verified' => 'bool',
            'display_name' => 'nullable|string|max:64',
            'unlock' => 'bool',
        ], ['role_id' => 'الدور', 'status' => 'الحالة'], true);
        $row = [];
        if (!empty($d['role_id']) && (int) $d['role_id'] !== (int) $target['role_id']) {
            if (!Auth::can('roles.assign')) {
                throw new HttpException(403, 'ليست لديك صلاحية تعيين الأدوار');
            }
            $level = (int) DB::value('SELECT level FROM roles WHERE id = ?', [(int) $d['role_id']]);
            if ($actor['role_slug'] !== 'super_admin' && $level >= (int) $actor['role_level']) {
                throw new HttpException(403, 'لا يمكنك منح دور مساوٍ لدورك أو أعلى');
            }
            if ($target['role_slug'] === 'super_admin' && self::superAdminCount() <= 1) {
                throw new HttpException(409, 'يجب أن يبقى مدير رئيسي واحد على الأقل');
            }
            $row['role_id'] = (int) $d['role_id'];
        }
        if (!empty($d['status'])) {
            if ($d['status'] === 'suspended' && $target['role_slug'] === 'super_admin' && self::superAdminCount() <= 1) {
                throw new HttpException(409, 'لا يمكن إيقاف آخر مدير رئيسي');
            }
            $row['status'] = $d['status'];
        }
        if (array_key_exists('can_download', $d)) {
            $row['can_download'] = $d['can_download'] ? 1 : 0;
        }
        if (array_key_exists('email_verified', $d)) {
            $row['email_verified_at'] = $d['email_verified'] ? ($target['email_verified_at'] ?: now()) : null;
        }
        if (array_key_exists('display_name', $d)) {
            $row['display_name'] = $d['display_name'];
        }
        if (!empty($d['unlock'])) {
            $row['locked_until'] = null;
            $row['failed_logins'] = 0;
        }
        if ($row) {
            $row['updated_at'] = now();
            DB::update('users', $row, 'id = ?', [$id]);
            if (($row['status'] ?? '') === 'suspended') {
                AuthService::revokeAllTokens($id);
            }
            ActivityLog::log('user.updated', 'user', $id, 'تعديل المستخدم ' . $target['username'], array_keys($row));
        }
        return Auth::loadUser($id);
    }

    public static function delete(array $actor, int $id): void
    {
        $target = Auth::loadUser($id) ?? throw new HttpException(404, 'المستخدم غير موجود');
        self::guard($actor, $target);
        if ($target['role_slug'] === 'super_admin' && self::superAdminCount() <= 1) {
            throw new HttpException(409, 'لا يمكن حذف آخر مدير رئيسي');
        }
        MediaService::deleteImage($target['avatar_path']);
        DB::delete('users', 'id = ?', [$id]);
        ActivityLog::log('user.deleted', 'user', $id, 'حذف المستخدم ' . $target['username']);
    }

    public static function superAdminCount(): int
    {
        return (int) DB::value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'super_admin' AND u.status = 'active'");
    }

    public static function create(array $input, int $roleId = 4, bool $verified = true): int
    {
        $d = Validator::validate($input, [
            'username' => 'required|string|min:3|max:32|regex:/^[A-Za-z0-9_.\-]+$/|unique:users,username',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => 'required|string|min:8|max:200',
        ], ['username' => 'اسم المستخدم', 'email' => 'البريد الإلكتروني', 'password' => 'كلمة المرور']);
        AuthService::assertStrong($d['password']);
        return DB::insert('users', [
            'role_id' => $roleId, 'username' => $d['username'], 'email' => $d['email'],
            'password_hash' => AuthService::hash($d['password']), 'email_verified_at' => $verified ? now() : null,
            'notifications_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Permission matrix (Super Admin only). The super_admin role always keeps every permission. */
    public static function permissionMatrix(): array
    {
        $perms = DB::all('SELECT * FROM permissions ORDER BY group_name, id');
        $map = [];
        foreach (DB::all('SELECT role_id, permission_id FROM role_permissions') as $r) {
            $map[(int) $r['role_id']][(int) $r['permission_id']] = true;
        }
        return ['roles' => self::roles(), 'permissions' => $perms, 'map' => $map];
    }

    public static function savePermissionMatrix(array $matrix): void
    {
        $roles = DB::all("SELECT id, slug FROM roles WHERE slug <> 'super_admin'");
        $permIds = array_map('intval', DB::column('SELECT id FROM permissions'));
        DB::transaction(static function () use ($roles, $permIds, $matrix): void {
            foreach ($roles as $role) {
                $rid = (int) $role['id'];
                DB::delete('role_permissions', 'role_id = ?', [$rid]);
                foreach ((array) ($matrix[$rid] ?? []) as $pid => $on) {
                    if ($on && in_array((int) $pid, $permIds, true)) {
                        DB::insert('role_permissions', ['role_id' => $rid, 'permission_id' => (int) $pid], true);
                    }
                }
            }
        });
        Auth::flushPermissions();
        ActivityLog::log('roles.updated', 'role', null, 'تعديل مصفوفة الصلاحيات');
    }
}
