<?php
declare(strict_types=1);

namespace App\Core;

/** Current-user context for both cookie sessions (web) and bearer tokens (apps). */
final class Auth
{
    private static ?array $user = null;
    private static string $via = 'none';
    private static ?int $tokenId = null;
    private static array $permCache = [];
    private static bool $system = false;

    /** Trusted command-line context (installer, seeder, cron): acts with every permission. */
    public static function actAsSystem(bool $on = true): void
    {
        self::$system = $on;
    }

    public static function isSystem(): bool
    {
        return self::$system;
    }

    public static function user(): ?array { return self::$user; }
    public static function id(): ?int { return self::$user ? (int) self::$user['id'] : null; }
    public static function check(): bool { return self::$user !== null; }
    public static function via(): string { return self::$via; }
    public static function tokenId(): ?int { return self::$tokenId; }
    public static function level(): int { return (int) (self::$user['role_level'] ?? 0); }
    public static function isVerified(): bool { return !empty(self::$user['email_verified_at']); }

    public static function set(?array $user, string $via = 'session', ?int $tokenId = null): void
    {
        self::$user = $user;
        self::$via = $user ? $via : 'none';
        self::$tokenId = $tokenId;
    }

    public static function loadUser(int $id): ?array
    {
        return DB::one(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.level AS role_level
               FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?',
            [$id]
        );
    }

    /** Changes whenever the password or status changes, so other sessions are invalidated. */
    public static function fingerprint(array $user): string
    {
        return substr(hash_hmac('sha256', $user['password_hash'] . '|' . $user['status'], (string) Config::get('app.key')), 0, 32);
    }

    public static function resolveSession(): void
    {
        $uid = Session::get('uid');
        if (!$uid) {
            return;
        }
        $u = self::loadUser((int) $uid);
        if (!$u || $u['status'] !== 'active' || !hash_equals((string) Session::get('auth_fp', ''), self::fingerprint($u))) {
            Session::forget('uid');
            Session::forget('auth_fp');
            return;
        }
        self::set($u, 'session');
    }

    public static function loginSession(array $user): void
    {
        Session::regenerate();
        Session::set('uid', (int) $user['id']);
        Session::set('auth_fp', self::fingerprint($user));
        Session::forget('_csrf');
        self::set(self::loadUser((int) $user['id']), 'session');
    }

    public static function refreshSessionFingerprint(): void
    {
        if (self::$user && self::$via === 'session') {
            $fresh = self::loadUser((int) self::$user['id']);
            if ($fresh) {
                Session::set('auth_fp', self::fingerprint($fresh));
                self::$user = $fresh;
            }
        }
    }

    public static function logout(): void
    {
        Session::destroy();
        self::set(null);
    }

    /** @return string[] */
    public static function permissions(): array
    {
        if (!self::$user) {
            return [];
        }
        $roleId = (int) self::$user['role_id'];
        if (!isset(self::$permCache[$roleId])) {
            self::$permCache[$roleId] = Cache::remember(Cache::key('perms', 'role:' . $roleId), 300, static fn () => DB::column(
                'SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?',
                [$roleId]
            ));
        }
        return self::$permCache[$roleId];
    }

    public static function can(string $permission): bool
    {
        if (self::$system && !self::$user) {
            return true;
        }
        if (!self::$user || self::$user['status'] !== 'active') {
            return false;
        }
        if (self::$user['role_slug'] === 'super_admin') {
            return true;
        }
        return in_array($permission, self::permissions(), true);
    }

    public static function flushPermissions(): void
    {
        self::$permCache = [];
        Cache::bump('perms');
    }
}
