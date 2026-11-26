<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\AuthService;
use App\Services\FileService;
use App\Services\GameService;
use App\Services\NotificationService;
use App\Services\UpdateService;
use App\Services\UserService;

final class AccountController extends Controller
{
    public function index(): Response
    {
        $u = $this->requireUser();
        $fav = GameService::favorites((int) $u['id'], 1, 6);
        return view('account/index', [
            'u' => $u, 'stats' => UserService::profileStats((int) $u['id']),
            'favorites' => $fav['items'], 'favIds' => array_column($fav['items'], 'id'),
            'downloads' => FileService::userDownloads((int) $u['id'], 1, 5)['items'],
            'history' => GameService::history((int) $u['id'], 1, 6)['items'],
            'updates' => UpdateService::forUser((int) $u['id'], 1, 4)['items'],
            'title' => 'حسابي',
        ]);
    }

    public function edit(): Response
    {
        $u = $this->requireUser();
        return view('account/edit', ['u' => $u, 'prefs' => json_decode((string) $u['preferences'], true) ?: [], 'title' => 'إعدادات الحساب']);
    }

    public function updateProfile(): Response
    {
        UserService::updateProfile($this->requireUser(), $this->request->post);
        return $this->done('تم حفظ الملف الشخصي', '/account/edit');
    }

    public function updateAvatar(): Response
    {
        $u = $this->requireUser();
        [$tmp] = $this->uploadedTmp('avatar');
        UserService::setAvatar((int) $u['id'], $tmp);
        return $this->done('تم تحديث الصورة الشخصية', '/account/edit');
    }

    public function removeAvatar(): Response
    {
        UserService::removeAvatar((int) $this->requireUser()['id']);
        return $this->done('تمت إزالة الصورة الشخصية', '/account/edit');
    }

    public function updatePassword(): Response
    {
        AuthService::changePassword($this->requireUser(), $this->request->post);
        Auth::refreshSessionFingerprint();
        return $this->done('تم تغيير كلمة المرور وتسجيل الخروج من الأجهزة الأخرى', '/account/edit');
    }

    public function preferences(): Response
    {
        $prefs = UserService::savePreferences((int) $this->requireUser()['id'], $this->request->all());
        return json_ok($prefs);
    }

    public function favorites(): Response
    {
        $u = $this->requireUser();
        $result = GameService::favorites((int) $u['id'], $this->page(), 24);
        return view('account/games', ['result' => $result, 'favIds' => array_map('intval', array_column($result['items'], 'id')),
            'kind' => 'favorites', 'title' => 'المفضلة']);
    }

    public function history(): Response
    {
        $u = $this->requireUser();
        $result = GameService::history((int) $u['id'], $this->page(), 24);
        return view('account/games', ['result' => $result, 'favIds' => GameService::favoriteIds((int) $u['id'], array_column($result['items'], 'id')),
            'kind' => 'history', 'title' => 'سجل المشاهدة']);
    }

    public function clearHistory(): Response
    {
        GameService::clearHistory((int) $this->requireUser()['id']);
        return $this->done('تم مسح سجل المشاهدة', '/account/history');
    }

    public function downloads(): Response
    {
        $u = $this->requireUser();
        return view('account/downloads', ['result' => FileService::userDownloads((int) $u['id'], $this->page(), 30), 'title' => 'سجل التنزيلات']);
    }

    public function updates(): Response
    {
        $u = $this->requireUser();
        return view('account/updates', ['result' => UpdateService::forUser((int) $u['id'], $this->page(), 20), 'title' => 'سجل التحديثات']);
    }

    public function notifications(): Response
    {
        $u = $this->requireUser();
        $result = NotificationService::forUser($u, $this->page(), $this->request->query('partial') ? 8 : 30);
        if ($this->request->query('partial')) {
            return json_ok(['html' => partial('notifications-list', ['items' => $result['items'], 'compact' => true]), 'unread' => $result['unread']]);
        }
        return view('account/notifications', ['result' => $result, 'title' => 'الإشعارات']);
    }

    public function readNotifications(): Response
    {
        $u = $this->requireUser();
        $id = $this->request->int('id');
        NotificationService::markRead($u, $id ?: null);
        return $this->done('تم تعليم الإشعارات كمقروءة', '/account/notifications', ['unread' => NotificationService::unreadCount(\App\Core\Auth::loadUser((int) $u['id']))]);
    }

    public function devices(): Response
    {
        $u = $this->requireUser();
        return view('account/devices', ['tokens' => AuthService::userTokens((int) $u['id']), 'title' => 'الأجهزة المتصلة']);
    }

    public function revokeDevice(string $id): Response
    {
        if (!AuthService::revokeToken((int) $id, (int) $this->requireUser()['id'])) {
            throw new HttpException(404, 'الجهاز غير موجود');
        }
        return $this->done('تم إلغاء ربط الجهاز', '/account/devices');
    }

    public function toggleFavorite(string $id): Response
    {
        $u = $this->requireUser();
        $on = $this->request->has('on') ? $this->request->bool('on') : null;
        $state = $on === null ? GameService::toggleFavorite((int) $u['id'], (int) $id) : GameService::setFavorite((int) $u['id'], (int) $id, $on);
        if ($this->request->wantsJson()) {
            $count = (int) \App\Core\DB::value('SELECT favorite_count FROM games WHERE id = ?', [(int) $id]);
            return json_ok(['favorite' => $state, 'count' => $count]);
        }
        flash('success', $state ? 'أضيفت إلى المفضلة ♥' : 'أزيلت من المفضلة');
        return back();
    }
}
