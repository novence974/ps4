<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Response;
use App\Services\ActivityLog;
use App\Services\AuthService;
use App\Services\Presenter;

final class AuthController extends ApiController
{
    /** POST /api/v1/auth/login {login|username|email, password, device_name} */
    public function login(): Response
    {
        $login = $this->request->str('login') ?: ($this->request->str('username') ?: $this->request->str('email'));
        $user = AuthService::attempt($login, (string) $this->request->input('password', ''), $this->request->ip());
        $token = AuthService::createToken($user, $this->request->str('device_name') ?: 'api', $this->request->ip());
        Auth::set($user, 'token', $token['token_id']);
        return $this->ok([
            'token' => $token['token'], 'token_type' => 'Bearer', 'expires_at' => $token['expires_at'],
            'user' => Presenter::user($user),
        ]);
    }

    /** POST /api/v1/auth/register {username, email, password, password_confirmation, device_name} */
    public function register(): Response
    {
        $user = AuthService::register($this->request->all());
        $token = AuthService::createToken($user, $this->request->str('device_name') ?: 'api', $this->request->ip());
        Auth::set($user, 'token', $token['token_id']);
        return $this->ok([
            'token' => $token['token'], 'token_type' => 'Bearer', 'expires_at' => $token['expires_at'],
            'user' => Presenter::user($user), 'verification_required' => empty($user['email_verified_at']),
        ], [], 201);
    }

    public function logout(): Response
    {
        if (Auth::via() === 'token' && Auth::tokenId()) {
            AuthService::revokeToken((int) Auth::tokenId(), Auth::id());
            ActivityLog::log('auth.logout', 'user', Auth::id(), 'تسجيل خروج من تطبيق');
        }
        return $this->ok(['message' => 'تم تسجيل الخروج']);
    }

    public function forgot(): Response
    {
        AuthService::requestReset($this->request->str('email'));
        return $this->ok(['message' => 'إن كان البريد مسجلاً فستصله رسالة الاستعادة']);
    }

    /** POST /api/v1/auth/reset-password {token, password, password_confirmation} — token from the e-mailed link */
    public function reset(): Response
    {
        AuthService::resetPassword($this->request->all());
        return $this->ok(['message' => 'تم تغيير كلمة المرور، سجّل الدخول من جديد']);
    }

    public function resendVerification(): Response
    {
        $u = $this->requireUser();
        if (empty($u['email_verified_at'])) {
            AuthService::sendVerification($u);
        }
        return $this->ok(['message' => 'تم الإرسال', 'email_verified' => !empty($u['email_verified_at'])]);
    }
}
