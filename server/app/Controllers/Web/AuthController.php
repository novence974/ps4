<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Response;
use App\Core\Session;
use App\Services\ActivityLog;
use App\Services\AuthService;
use App\Services\Settings;

final class AuthController extends Controller
{
    public function loginForm(): Response
    {
        return view('auth/login', ['title' => 'تسجيل الدخول']);
    }

    public function login(): Response
    {
        $user = AuthService::attempt($this->request->str('login'), (string) $this->request->input('password', ''), $this->request->ip());
        Auth::loginSession($user);
        $to = (string) Session::pull('intended', '');
        if ($to === '' || !str_starts_with($to, '/') || str_starts_with($to, '//') || str_contains($to, '\\')) {
            $to = Auth::can('admin.access') ? '/admin' : '/account';
        }
        flash('success', 'أهلاً بعودتك يا ' . user_label($user) . ' 👋');
        return redirect($to);
    }

    public function registerForm(): Response
    {
        if (!Settings::get('auth.registration_open', true)) {
            flash('error', 'التسجيل مغلق حالياً');
            return redirect('/login');
        }
        return view('auth/register', ['title' => 'إنشاء حساب']);
    }

    public function register(): Response
    {
        $user = AuthService::register($this->request->post);
        Auth::loginSession($user);
        flash('success', $user['email_verified_at']
            ? 'تم إنشاء حسابك بنجاح، مرحباً بك!'
            : 'تم إنشاء حسابك! أرسلنا رابط التأكيد إلى بريدك ' . $user['email']);
        return redirect('/account');
    }

    public function logout(): Response
    {
        if (Auth::check()) {
            ActivityLog::log('auth.logout', 'user', Auth::id(), 'تسجيل خروج');
        }
        Auth::logout();
        Session::start($this->request);
        flash('success', 'تم تسجيل الخروج');
        return redirect('/');
    }

    public function forgotForm(): Response
    {
        return view('auth/forgot', ['title' => 'استعادة كلمة المرور']);
    }

    public function forgot(): Response
    {
        AuthService::requestReset($this->request->str('email'));
        flash('success', 'إن كان البريد مسجلاً لدينا فستصلك رسالة فيها رابط تعيين كلمة مرور جديدة خلال دقائق.');
        return redirect('/login');
    }

    public function resetForm(): Response
    {
        return view('auth/reset', ['token' => $this->request->str('token'), 'title' => 'تعيين كلمة مرور جديدة']);
    }

    public function reset(): Response
    {
        AuthService::resetPassword($this->request->post);
        flash('success', 'تم تغيير كلمة المرور، يمكنك تسجيل الدخول الآن');
        return redirect('/login');
    }

    public function verify(): Response
    {
        $ok = AuthService::verifyEmail($this->request->str('token'));
        flash($ok ? 'success' : 'error', $ok ? 'تم تأكيد بريدك الإلكتروني بنجاح ✓' : 'رابط التأكيد غير صالح أو منتهي، اطلب رابطاً جديداً من صفحة حسابك');
        return redirect(Auth::check() ? '/account' : '/login');
    }

    public function resend(): Response
    {
        $user = $this->requireUser();
        if (!empty($user['email_verified_at'])) {
            return $this->done('بريدك مؤكد مسبقاً');
        }
        AuthService::sendVerification($user);
        return $this->done('أرسلنا رابط تأكيد جديداً إلى ' . $user['email']);
    }
}
