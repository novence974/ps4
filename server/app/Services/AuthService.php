<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Mailer;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Core\View;

final class AuthService
{
    public static function hash(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    private static function algo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function passwordRules(): string
    {
        return 'required|string|min:' . max(8, (int) Settings::get('auth.password_min', 8)) . '|max:200|confirmed';
    }

    public static function assertStrong(string $password, string $field = 'password'): void
    {
        if (!preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
            throw ValidationException::single($field, 'كلمة المرور يجب أن تحتوي على حروف وأرقام معاً');
        }
    }

    public static function register(array $input): array
    {
        if (!Settings::get('auth.registration_open', true)) {
            throw new HttpException(403, 'التسجيل مغلق حالياً من قبل الإدارة');
        }
        $data = Validator::validate($input, [
            'username' => 'required|string|min:3|max:32|regex:/^[A-Za-z0-9_.\-]+$/|unique:users,username',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => self::passwordRules(),
        ], ['username' => 'اسم المستخدم', 'email' => 'البريد الإلكتروني', 'password' => 'كلمة المرور']);
        self::assertStrong($data['password']);
        $needsVerify = (bool) Settings::get('auth.email_verification', true);
        $id = DB::insert('users', [
            'role_id' => 4,
            'username' => $data['username'],
            'email' => $data['email'],
            'password_hash' => self::hash($data['password']),
            'email_verified_at' => $needsVerify ? null : now(),
            'created_at' => now(),
            'updated_at' => now(),
            'notifications_seen_at' => now(),
        ]);
        $user = Auth::loadUser($id);
        ActivityLog::log('user.register', 'user', $id, 'حساب جديد: ' . $data['username'], [], $id);
        if ($needsVerify) {
            self::sendVerification($user);
        }
        NotificationService::toUser($id, 'welcome', 'مرحباً بك في ' . Settings::get('site.name'), 'استكشف المكتبة وأضف ألعابك إلى المفضلة لتصلك تحديثاتها.', '/');
        return $user;
    }

    /** Verifies credentials with per-account lockout. Returns the fresh user row. */
    public static function attempt(string $login, string $password, string $ip): array
    {
        $login = trim($login);
        $fail = static fn () => throw new ValidationException(['login' => 'اسم المستخدم أو كلمة المرور غير صحيحة'], 'اسم المستخدم أو كلمة المرور غير صحيحة');
        if ($login === '' || $password === '') {
            $fail();
        }
        $user = DB::one('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1', [$login, mb_strtolower($login)]);
        if (!$user) {
            self::hash($password); // equalise timing for unknown accounts
            $fail();
        }
        if ($user['locked_until'] && strtotime($user['locked_until'] . ' UTC') > time()) {
            $mins = (int) ceil((strtotime($user['locked_until'] . ' UTC') - time()) / 60);
            throw new HttpException(429, "تم قفل الحساب مؤقتاً بسبب محاولات فاشلة متكررة. حاول بعد {$mins} دقيقة", ['Retry-After' => (string) ($mins * 60)], 'account_locked');
        }
        if (!password_verify($password, $user['password_hash'])) {
            $failed = (int) $user['failed_logins'] + 1;
            $update = ['failed_logins' => $failed];
            if ($failed >= max(3, (int) Settings::get('auth.max_attempts', 5))) {
                $update['locked_until'] = gmdate('Y-m-d H:i:s', time() + 60 * max(1, (int) Settings::get('auth.lockout_minutes', 15)));
                $update['failed_logins'] = 0;
                ActivityLog::log('auth.locked', 'user', (int) $user['id'], 'قفل مؤقت بعد محاولات فاشلة', ['ip' => $ip], (int) $user['id']);
            }
            DB::update('users', $update, 'id = ?', [(int) $user['id']]);
            ActivityLog::log('auth.failed', 'user', (int) $user['id'], 'محاولة دخول فاشلة', ['ip' => $ip], (int) $user['id']);
            $fail();
        }
        if ($user['status'] !== 'active') {
            throw new HttpException(403, 'هذا الحساب موقوف، تواصل مع الإدارة', [], 'account_suspended');
        }
        $update = ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_login_ip' => $ip];
        if (password_needs_rehash($user['password_hash'], self::algo())) {
            $update['password_hash'] = self::hash($password);
        }
        DB::update('users', $update, 'id = ?', [(int) $user['id']]);
        ActivityLog::log('auth.login', 'user', (int) $user['id'], 'تسجيل دخول', ['ip' => $ip], (int) $user['id']);
        return Auth::loadUser((int) $user['id']);
    }

    // ------------------------------------------------------------------ API tokens

    public static function createToken(array $user, string $name, string $ip = ''): array
    {
        $plain = 'upsl_' . bin2hex(random_bytes(32));
        $days = max(1, (int) Settings::get('api.token_ttl_days', 30));
        $expires = gmdate('Y-m-d H:i:s', time() + $days * 86400);
        $id = DB::insert('api_tokens', [
            'user_id' => (int) $user['id'],
            'name' => mb_substr(trim($name) !== '' ? trim($name) : 'device', 0, 100),
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $expires,
            'last_used_at' => now(),
            'last_used_ip' => $ip ?: null,
            'created_at' => now(),
        ]);
        ActivityLog::log('auth.token_created', 'api_token', $id, 'رمز API جديد: ' . $name, [], (int) $user['id']);
        return ['token' => $plain, 'token_type' => 'Bearer', 'expires_at' => iso_date($expires), 'token_id' => $id];
    }

    /** @return array{user:array,token_id:int}|null */
    public static function resolveToken(string $plain, string $ip): ?array
    {
        $row = DB::one('SELECT id, user_id, expires_at, revoked_at, last_used_at FROM api_tokens WHERE token_hash = ?', [hash('sha256', $plain)]);
        if (!$row || $row['revoked_at'] || ($row['expires_at'] && strtotime($row['expires_at'] . ' UTC') < time())) {
            return null;
        }
        $user = Auth::loadUser((int) $row['user_id']);
        if (!$user || $user['status'] !== 'active') {
            return null;
        }
        if (!$row['last_used_at'] || strtotime($row['last_used_at'] . ' UTC') < time() - 300) {
            DB::update('api_tokens', ['last_used_at' => now(), 'last_used_ip' => $ip], 'id = ?', [(int) $row['id']]);
        }
        return ['user' => $user, 'token_id' => (int) $row['id']];
    }

    public static function revokeToken(int $tokenId, ?int $userId = null): bool
    {
        $sql = 'id = ? AND revoked_at IS NULL' . ($userId ? ' AND user_id = ?' : '');
        $n = DB::update('api_tokens', ['revoked_at' => now()], $sql, $userId ? [$tokenId, $userId] : [$tokenId]);
        if ($n) {
            ActivityLog::log('auth.token_revoked', 'api_token', $tokenId, 'إلغاء رمز API');
        }
        return $n > 0;
    }

    public static function revokeAllTokens(int $userId, ?int $exceptId = null): void
    {
        DB::run(
            'UPDATE api_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL' . ($exceptId ? ' AND id <> ?' : ''),
            $exceptId ? [now(), $userId, $exceptId] : [now(), $userId]
        );
    }

    public static function userTokens(int $userId): array
    {
        return DB::all('SELECT id, name, last_used_at, last_used_ip, expires_at, created_at FROM api_tokens WHERE user_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?) ORDER BY last_used_at DESC', [$userId, now()]);
    }

    // ------------------------------------------------------------------ e-mail verification & reset

    private static function issue(int $userId, string $type, int $ttl): string
    {
        DB::run('UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND type = ? AND used_at IS NULL', [now(), $userId, $type]);
        $token = bin2hex(random_bytes(32));
        DB::insert('user_tokens', [
            'user_id' => $userId, 'type' => $type, 'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl), 'created_at' => now(),
        ]);
        return $token;
    }

    private static function consume(string $token, string $type): ?int
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = DB::one('SELECT * FROM user_tokens WHERE token_hash = ? AND type = ?', [hash('sha256', $token), $type]);
        if (!$row || $row['used_at'] || strtotime($row['expires_at'] . ' UTC') < time()) {
            return null;
        }
        DB::update('user_tokens', ['used_at' => now()], 'id = ?', [(int) $row['id']]);
        return (int) $row['user_id'];
    }

    public static function sendVerification(array $user): bool
    {
        $token = self::issue((int) $user['id'], 'verify_email', 48 * 3600);
        $html = View::render('emails/action', [
            'heading' => 'تأكيد البريد الإلكتروني',
            'intro' => 'مرحباً ' . ($user['display_name'] ?: $user['username']) . '، اضغط الزر لتأكيد بريدك وتفعيل التنزيلات.',
            'action' => 'تأكيد البريد', 'link' => abs_url('verify-email?token=' . $token),
            'note' => 'صلاحية الرابط 48 ساعة. إن لم تنشئ هذا الحساب تجاهل الرسالة.',
        ], 'emails/layout');
        return Mailer::send($user['email'], 'تأكيد البريد الإلكتروني — ' . Settings::get('site.name'), $html);
    }

    public static function verifyEmail(string $token): bool
    {
        $uid = self::consume($token, 'verify_email');
        if (!$uid) {
            return false;
        }
        DB::update('users', ['email_verified_at' => now(), 'updated_at' => now()], 'id = ? AND email_verified_at IS NULL', [$uid]);
        ActivityLog::log('user.verified', 'user', $uid, 'تم تأكيد البريد', [], $uid);
        return true;
    }

    /** Always behaves the same whether the e-mail exists or not (no account enumeration). */
    public static function requestReset(string $email): void
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::single('email', 'البريد الإلكتروني غير صحيح');
        }
        $user = DB::one('SELECT * FROM users WHERE email = ? AND status = ? LIMIT 1', [$email, 'active']);
        if (!$user) {
            usleep(random_int(150000, 300000));
            return;
        }
        $token = self::issue((int) $user['id'], 'reset_password', 3600);
        $html = View::render('emails/action', [
            'heading' => 'استعادة كلمة المرور',
            'intro' => 'تلقينا طلباً لإعادة تعيين كلمة المرور لحسابك ' . $user['username'] . '.',
            'action' => 'تعيين كلمة مرور جديدة', 'link' => abs_url('reset-password?token=' . $token),
            'note' => 'صلاحية الرابط ساعة واحدة. إن لم تطلب ذلك تجاهل الرسالة وكلمة مرورك الحالية تبقى كما هي.',
        ], 'emails/layout');
        Mailer::send($user['email'], 'استعادة كلمة المرور — ' . Settings::get('site.name'), $html);
        ActivityLog::log('auth.reset_requested', 'user', (int) $user['id'], 'طلب استعادة كلمة المرور', [], (int) $user['id']);
    }

    public static function resetPassword(array $input): int
    {
        $data = Validator::validate($input, ['token' => 'required|string', 'password' => self::passwordRules()], ['password' => 'كلمة المرور']);
        self::assertStrong($data['password']);
        $uid = self::consume((string) $data['token'], 'reset_password');
        if (!$uid) {
            throw ValidationException::single('token', 'رابط الاستعادة غير صالح أو منتهي الصلاحية، اطلب رابطاً جديداً');
        }
        DB::update('users', ['password_hash' => self::hash($data['password']), 'failed_logins' => 0, 'locked_until' => null, 'updated_at' => now()], 'id = ?', [$uid]);
        self::revokeAllTokens($uid);
        ActivityLog::log('auth.password_reset', 'user', $uid, 'إعادة تعيين كلمة المرور', [], $uid);
        return $uid;
    }

    public static function changePassword(array $user, array $input): void
    {
        $data = Validator::validate($input, ['current_password' => 'required|string', 'password' => self::passwordRules()], ['current_password' => 'كلمة المرور الحالية', 'password' => 'كلمة المرور الجديدة']);
        if (!password_verify($data['current_password'], $user['password_hash'])) {
            throw ValidationException::single('current_password', 'كلمة المرور الحالية غير صحيحة');
        }
        self::assertStrong($data['password']);
        DB::update('users', ['password_hash' => self::hash($data['password']), 'updated_at' => now()], 'id = ?', [(int) $user['id']]);
        self::revokeAllTokens((int) $user['id'], Auth::tokenId());
        ActivityLog::log('auth.password_changed', 'user', (int) $user['id'], 'تغيير كلمة المرور');
    }
}
