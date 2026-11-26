<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Hardened session handling: strict mode, HttpOnly + SameSite cookies, Secure on HTTPS,
 * idle and absolute timeouts, ID regeneration on privilege change, read-only mode for API calls.
 */
final class Session
{
    private static bool $started = false;
    private static bool $readOnly = false;

    private static function configure(Request $r): void
    {
        $secure = Config::get('session.secure', 'auto');
        $secure = $secure === 'auto' ? $r->isSecure() : (bool) $secure;
        $dir = STORAGE_PATH . '/sessions';
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        session_name((string) Config::get('session.name', 'UPSLSESS'));
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) max(1800, self::idleSeconds()));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (Request::basePath() ?: '') . '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function idleSeconds(): int
    {
        try {
            return max(5, (int) \App\Services\Settings::get('auth.session_idle_minutes', 120)) * 60;
        } catch (\Throwable) {
            return 7200;
        }
    }

    public static function start(Request $r): void
    {
        if (self::$started) {
            return;
        }
        self::configure($r);
        session_start();
        self::$started = true;
        $now = time();
        $expired = (isset($_SESSION['_last']) && $now - (int) $_SESSION['_last'] > self::idleSeconds())
            || (isset($_SESSION['_created']) && $now - (int) $_SESSION['_created'] > (int) Config::get('session.absolute_seconds', 604800));
        if ($expired) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_created'] ??= $now;
        $_SESSION['_last'] = $now;
        $_SESSION['_flash_old'] = $_SESSION['_flash_new'] ?? [];
        $_SESSION['_flash_new'] = [];
    }

    /** For API calls authenticated by cookie: read the session without holding its lock. */
    public static function startReadOnly(Request $r): void
    {
        if (self::$started) {
            return;
        }
        self::configure($r);
        session_start(['read_and_close' => true]);
        self::$started = true;
        self::$readOnly = true;
        if (isset($_SESSION['_last']) && time() - (int) $_SESSION['_last'] > self::idleSeconds()) {
            $_SESSION = [];
        }
    }

    public static function started(): bool
    {
        return self::$started;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        if (self::$started && !self::$readOnly) {
            $_SESSION[$key] = $value;
        }
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $v = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $v;
    }

    public static function flash(string $key, mixed $value): void
    {
        if (self::$started && !self::$readOnly) {
            $_SESSION['_flash_new'][$key] = $value;
        }
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash_new'][$key] ?? $_SESSION['_flash_old'][$key] ?? $default;
    }

    public static function regenerate(): void
    {
        if (self::$started && !self::$readOnly) {
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }
    }

    public static function destroy(): void
    {
        if (!self::$started) {
            return;
        }
        $_SESSION = [];
        if (!self::$readOnly) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            session_destroy();
        }
        self::$started = false;
    }
}
