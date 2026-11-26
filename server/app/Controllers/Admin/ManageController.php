<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\ActivityLog;
use App\Services\CatalogService;
use App\Services\FileService;
use App\Services\NotificationService;
use App\Services\UpdateService;
use App\Services\UserService;

/** Platforms, categories, users & roles, updates and notifications. */
final class ManageController extends Controller
{
    private function render(string $tpl, array $data): Response
    {
        return view('admin/' . $tpl, $data, 'layouts/admin');
    }

    // ------------------------------------------------------------------ platforms
    public function platforms(): Response
    {
        $this->authorize('platforms.manage');
        return $this->render('catalog/platforms', ['platforms' => CatalogService::platforms(false), 'title' => 'إدارة الأجهزة']);
    }

    public function platformForm(?string $id = null): Response
    {
        $this->authorize('platforms.manage');
        $p = $id ? (CatalogService::platform((int) $id) ?? throw new HttpException(404, 'الجهاز غير موجود')) : null;
        return $this->render('catalog/platform-form', ['p' => $p, 'title' => $p ? 'تعديل ' . $p['name'] : 'إضافة جهاز']);
    }

    public function platformSave(?string $id = null): Response
    {
        $this->authorize('platforms.manage');
        $pid = CatalogService::savePlatform($id ? (int) $id : null, $this->request->post);
        return $this->done('تم حفظ بيانات الجهاز', '/admin/platforms/' . $pid . '/edit');
    }

    public function platformImage(string $id, string $kind): Response
    {
        $this->authorize('platforms.manage');
        [$tmp] = $this->uploadedTmp('file');
        CatalogService::setPlatformImage((int) $id, $kind === 'logo' ? 'logo' : 'image', $tmp);
        return $this->done($kind === 'logo' ? 'تم تحديث الشعار' : 'تم تحديث صورة الجهاز', '/admin/platforms/' . (int) $id . '/edit');
    }

    // ------------------------------------------------------------------ categories
    public function categories(): Response
    {
        $this->authorize('categories.manage');
        $edit = $this->request->int('edit');
        return $this->render('catalog/categories', ['categories' => CatalogService::categories(),
            'edit' => $edit ? CatalogService::category($edit) : null, 'title' => 'التصنيفات']);
    }

    public function categorySave(?string $id = null): Response
    {
        $this->authorize('categories.manage');
        CatalogService::saveCategory($id ? (int) $id : null, $this->request->post);
        return $this->done('تم حفظ التصنيف', '/admin/categories');
    }

    public function categoryDelete(string $id): Response
    {
        $this->authorize('categories.manage');
        CatalogService::deleteCategory((int) $id);
        return $this->done('تم حذف التصنيف', '/admin/categories');
    }

    // ------------------------------------------------------------------ users
    public function users(): Response
    {
        $this->authorize('users.view', 'users.manage');
        return $this->render('users/index', ['result' => UserService::list($this->request->query, $this->page(), 30),
            'roles' => UserService::roles(), 'filters' => $this->request->query, 'title' => 'المستخدمون']);
    }

    public function showUser(string $id): Response
    {
        $this->authorize('users.view', 'users.manage');
        $u = Auth::loadUser((int) $id) ?? throw new HttpException(404, 'المستخدم غير موجود');
        return $this->render('users/show', [
            'u' => $u, 'roles' => UserService::roles(), 'stats' => UserService::profileStats((int) $id),
            'activity' => ActivityLog::list(['user_id' => (int) $id], 1, 15)['items'],
            'downloads' => FileService::userDownloads((int) $id, 1, 10)['items'],
            'tokens' => \App\Services\AuthService::userTokens((int) $id), 'title' => 'المستخدم ' . $u['username'],
        ]);
    }

    public function userUpdate(string $id): Response
    {
        $this->authorize('users.manage');
        UserService::adminUpdate($this->requireUser(), (int) $id, $this->request->post);
        return $this->done('تم حفظ بيانات المستخدم', '/admin/users/' . (int) $id);
    }

    public function userDelete(string $id): Response
    {
        $this->authorize('users.manage');
        UserService::delete($this->requireUser(), (int) $id);
        return $this->done('تم حذف المستخدم', '/admin/users');
    }

    public function userCreate(): Response
    {
        $this->authorize('users.manage');
        $roleId = $this->request->int('role_id', 4);
        $level = (int) DB::value('SELECT level FROM roles WHERE id = ?', [$roleId]);
        $actor = $this->requireUser();
        if (!$level || ($roleId !== 4 && !Auth::can('roles.assign')) || ($actor['role_slug'] !== 'super_admin' && $level >= (int) $actor['role_level'])) {
            throw new HttpException(403, 'لا يمكنك إنشاء حساب بهذا الدور');
        }
        $id = UserService::create($this->request->post, $roleId, true);
        ActivityLog::log('user.register', 'user', $id, 'إنشاء حساب من لوحة التحكم');
        return $this->done('تم إنشاء الحساب', '/admin/users/' . $id);
    }

    public function roles(): Response
    {
        $this->authorize('roles.assign');
        return $this->render('users/roles', ['matrix' => UserService::permissionMatrix(), 'title' => 'الأدوار والصلاحيات']);
    }

    public function rolesSave(): Response
    {
        if (Auth::user()['role_slug'] !== 'super_admin') {
            throw new HttpException(403, 'تعديل مصفوفة الصلاحيات للمدير الرئيسي فقط');
        }
        UserService::savePermissionMatrix((array) ($this->request->post['perm'] ?? []));
        return $this->done('تم حفظ الصلاحيات', '/admin/roles');
    }

    // ------------------------------------------------------------------ updates
    public function updates(): Response
    {
        $this->authorize('updates.manage');
        $edit = $this->request->int('edit');
        return $this->render('content/updates', [
            'result' => UpdateService::list($this->page(), 20, true, $this->request->str('type') ?: null),
            'edit' => $edit ? UpdateService::find($edit) : null,
            'games' => DB::all('SELECT id, title FROM games ORDER BY updated_at DESC LIMIT 300'),
            'platforms' => CatalogService::platforms(false), 'title' => 'التحديثات',
        ]);
    }

    public function updateSave(?string $id = null): Response
    {
        $this->authorize('updates.manage');
        UpdateService::save($id ? (int) $id : null, $this->request->post);
        return $this->done('تم حفظ التحديث', '/admin/updates');
    }

    public function updateDelete(string $id): Response
    {
        $this->authorize('updates.manage');
        UpdateService::delete((int) $id);
        return $this->done('تم حذف التحديث', '/admin/updates');
    }

    // ------------------------------------------------------------------ notifications
    public function notifications(): Response
    {
        $this->authorize('notifications.send');
        return $this->render('content/notifications', ['result' => NotificationService::adminList($this->page()), 'title' => 'الإشعارات']);
    }

    public function notificationSend(): Response
    {
        $this->authorize('notifications.send');
        NotificationService::send($this->request->post, (int) Auth::id());
        return $this->done('تم إرسال الإشعار', '/admin/notifications');
    }

    public function notificationDelete(string $id): Response
    {
        $this->authorize('notifications.send');
        NotificationService::delete((int) $id);
        return $this->done('تم حذف الإشعار', '/admin/notifications');
    }
}
