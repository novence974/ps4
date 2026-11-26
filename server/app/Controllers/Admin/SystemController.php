<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Cache;
use App\Core\DB;
use App\Core\FileStreamer;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Mailer;
use App\Core\Response;
use App\Core\ValidationException;
use App\Services\ActivityLog;
use App\Services\AdminSettings;
use App\Services\AuthService;
use App\Services\BackupService;
use App\Services\FileService;
use App\Services\MediaService;
use App\Services\Queue;
use App\Services\Settings;
use App\Services\SystemService;
use App\Services\UploadService;

/** Dashboard, upload manager, files, downloads, storage, API, security, logs, backups and settings. */
final class SystemController extends Controller
{
    private function render(string $tpl, array $data): Response
    {
        return view('admin/' . $tpl, $data, 'layouts/admin');
    }

    public function dashboard(): Response
    {
        return $this->render('dashboard', [
            'stats' => SystemService::dashboard(), 'health' => SystemService::health(true),
            'activity' => Auth::can('logs.view') ? ActivityLog::list([], 1, 8)['items'] : [], 'title' => 'لوحة التحكم',
        ]);
    }

    public function health(): Response
    {
        return json_ok(SystemService::health(true));
    }

    public function runQueue(): Response
    {
        $this->authorize('files.upload', 'settings.manage');
        $r = Queue::work(25, 50);
        $c = UploadService::cleanupStale();
        return $this->done("تمت معالجة {$r['done']} مهمة" . ($r['failed'] ? " وفشلت {$r['failed']}" : '') . "، وتنظيف {$c} عملية رفع قديمة");
    }

    // ------------------------------------------------------------------ files
    public function uploads(): Response
    {
        $this->authorize('files.upload', 'files.manage');
        return $this->render('system/uploads', [
            'result' => UploadService::list($this->request->query, $this->page(), 25),
            'games' => DB::all('SELECT g.id, g.title, p.short_name FROM games g JOIN platforms p ON p.id = g.platform_id ORDER BY g.updated_at DESC LIMIT 500'),
            'allowedExt' => UploadService::allowedGameExtensions(), 'chunkSize' => UploadService::chunkSize(),
            'queue' => Queue::stats(), 'title' => 'مدير الرفع',
        ]);
    }

    public function files(): Response
    {
        $this->authorize('files.manage');
        return $this->render('system/files', ['result' => FileService::adminList($this->request->query, $this->page(), 40), 'filters' => $this->request->query, 'title' => 'إدارة الملفات']);
    }

    public function downloads(): Response
    {
        $this->authorize('downloads.view');
        $page = $this->page();
        $per = 50;
        $w = '1=1';
        $p = [];
        if ($q = $this->request->str('q')) {
            $w = '(u.username LIKE :q1 OR g.title LIKE :q2 OR d.ip = :q3)';
            $p = ['q1' => "%$q%", 'q2' => "%$q%", 'q3' => $q];
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM downloads d LEFT JOIN users u ON u.id = d.user_id JOIN games g ON g.id = d.game_id WHERE $w", $p);
        $rows = DB::all("SELECT d.*, u.username, g.title, g.slug, f.original_name, f.size_bytes FROM downloads d LEFT JOIN users u ON u.id = d.user_id
                JOIN games g ON g.id = d.game_id LEFT JOIN game_files f ON f.id = d.file_id WHERE $w ORDER BY d.id DESC LIMIT :lim OFFSET :off",
            $p + ['lim' => $per, 'off' => ($page - 1) * $per]);
        $top = DB::all('SELECT g.id, g.title, COUNT(*) AS c FROM downloads d JOIN games g ON g.id = d.game_id WHERE d.created_at >= ? GROUP BY g.id, g.title ORDER BY c DESC LIMIT 8', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        return $this->render('system/downloads', ['result' => ['items' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per))],
            'top' => $top, 'stats' => SystemService::dashboard(), 'title' => 'سجل التنزيلات']);
    }

    public function storage(): Response
    {
        $this->authorize('storage.manage');
        $showOrphans = $this->request->bool('orphans');
        return $this->render('system/storage', ['o' => SystemService::storageOverview(), 'orphans' => $showOrphans ? SystemService::orphans() : null, 'title' => 'التخزين']);
    }

    public function storageAction(string $action): Response
    {
        $this->authorize('storage.manage');
        switch ($action) {
            case 'cleanup':
                $n = UploadService::cleanupStale(0);
                ActivityLog::log('storage.cleanup', 'storage', null, "تنظيف {$n} عملية رفع/أجزاء");
                return $this->done("تم تنظيف {$n} عملية رفع غير مكتملة", '/admin/storage');
            case 'orphans':
                $r = SystemService::deleteOrphans();
                return $this->done("تم حذف {$r['deleted']} ملف يتيم (" . human_bytes($r['bytes']) . ')', '/admin/storage');
            case 'cache':
                Cache::flush();
                Settings::forget();
                ActivityLog::log('cache.cleared', 'cache', null, 'مسح الكاش');
                return $this->done('تم مسح الكاش (' . Cache::driver() . ')', '/admin/storage');
        }
        throw new HttpException(404);
    }

    // ------------------------------------------------------------------ API
    public function api(): Response
    {
        $this->authorize('api.manage');
        $tokens = DB::all('SELECT t.*, u.username FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > ?) ORDER BY t.last_used_at DESC LIMIT 200', [now()]);
        return $this->render('system/api', ['tokens' => $tokens, 'title' => 'إعدادات API']);
    }

    public function apiSave(): Response
    {
        $this->authorize('api.manage');
        AdminSettings::save('api', $this->request->post, false);
        return $this->done('تم حفظ إعدادات API', '/admin/api');
    }

    public function revokeToken(string $id): Response
    {
        $this->authorize('api.manage');
        AuthService::revokeToken((int) $id);
        return $this->done('تم إلغاء الرمز', '/admin/api');
    }

    // ------------------------------------------------------------------ security
    public function security(): Response
    {
        $this->authorize('security.manage');
        return $this->render('system/security', [
            'locked' => DB::all('SELECT id, username, email, locked_until FROM users WHERE locked_until > ? ORDER BY locked_until DESC', [now()]),
            'failed' => DB::all("SELECT a.*, u.username FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.action IN ('auth.failed','auth.locked') ORDER BY a.id DESC LIMIT 15"),
            'health' => SystemService::health(true), 'disks' => array_keys(FileService::disks()), 'title' => 'الأمان',
        ]);
    }

    public function securitySave(): Response
    {
        $this->authorize('security.manage');
        AdminSettings::save('security', $this->request->post, false);
        return $this->done('تم حفظ إعدادات الأمان', '/admin/security');
    }

    public function unlock(string $id): Response
    {
        $this->authorize('security.manage');
        DB::update('users', ['locked_until' => null, 'failed_logins' => 0], 'id = ?', [(int) $id]);
        ActivityLog::log('user.updated', 'user', (int) $id, 'فك قفل الحساب');
        return $this->done('تم فك قفل الحساب', '/admin/security');
    }

    // ------------------------------------------------------------------ logs
    public function logs(): Response
    {
        $this->authorize('logs.view');
        return $this->render('system/logs', ['result' => ActivityLog::list($this->request->query, $this->page(), 50), 'filters' => $this->request->query, 'title' => 'سجل النشاط']);
    }

    public function errors(): Response
    {
        $this->authorize('logs.view');
        $level = $this->request->str('level');
        return $this->render('system/errors', ['entries' => Logger::tail(300, in_array($level, ['error', 'warning', 'info'], true) ? $level : null),
            'level' => $level, 'title' => 'سجل الأخطاء']);
    }

    // ------------------------------------------------------------------ backups
    public function backups(): Response
    {
        $this->authorize('backups.manage');
        return $this->render('system/backups', ['backups' => BackupService::list(), 'title' => 'النسخ الاحتياطي والاستعادة']);
    }

    public function backupCreate(): Response
    {
        $this->authorize('backups.manage');
        $name = BackupService::create($this->request->bool('include_media'));
        return $this->done('تم إنشاء النسخة ' . $name, '/admin/backups');
    }

    public function backupDownload(string $name): Response
    {
        $this->authorize('backups.manage');
        return FileStreamer::response($this->request, BackupService::path($name), $name, 'application/octet-stream');
    }

    public function backupRestore(string $name): Response
    {
        $this->authorize('backups.restore');
        $user = $this->requireUser();
        if (!password_verify((string) $this->request->input('password', ''), $user['password_hash'])) {
            throw ValidationException::single('password', 'كلمة المرور غير صحيحة، لم تتم الاستعادة');
        }
        $safety = BackupService::restore($name, $this->request->bool('with_media', true));
        flash('success', 'تمت الاستعادة من ' . $name . '. نسخة الأمان قبل الاستعادة: ' . $safety);
        return redirect('/admin/backups');
    }

    public function backupDelete(string $name): Response
    {
        $this->authorize('backups.manage');
        BackupService::delete($name);
        return $this->done('تم حذف النسخة', '/admin/backups');
    }

    public function backupSettings(): Response
    {
        $this->authorize('backups.manage');
        AdminSettings::save('backups', $this->request->post, false);
        return $this->done('تم حفظ جدولة النسخ', '/admin/backups');
    }

    // ------------------------------------------------------------------ general settings
    public function settings(): Response
    {
        $this->authorize('settings.manage');
        return $this->render('system/settings', ['mail' => Mailer::config(), 'title' => 'الإعدادات العامة']);
    }

    public function settingsSave(): Response
    {
        $this->authorize('settings.manage');
        AdminSettings::save('general', $this->request->post, false);
        return $this->done('تم حفظ الإعدادات', '/admin/settings');
    }

    public function mailTest(): Response
    {
        $this->authorize('settings.manage');
        $u = $this->requireUser();
        $html = \App\Core\View::render('emails/action', ['heading' => 'رسالة اختبار', 'intro' => 'إعدادات البريد تعمل بشكل صحيح ✓',
            'action' => 'فتح المكتبة', 'link' => abs_url('/'), 'note' => 'أرسلت من صفحة الإعدادات.'], 'emails/layout');
        $ok = Mailer::send($u['email'], 'اختبار البريد — ' . Settings::get('site.name'), $html);
        if (!$ok) {
            flash('error', 'فشل الإرسال، راجع سجل الأخطاء لمعرفة السبب');
            return redirect('/admin/settings');
        }
        return $this->done('تم إرسال رسالة اختبار إلى ' . $u['email'] . (Mailer::config()['driver'] === 'log' ? ' (وضع السجل: storage/mail)' : ''), '/admin/settings');
    }

    public function musicUpload(): Response
    {
        $this->authorize('settings.manage');
        [$tmp, $name] = $this->uploadedTmp('file');
        $rel = MediaService::storeFile($tmp, 'music', $name);
        MediaService::deleteFile((string) Settings::get('music.track', ''));
        Settings::set('music.track', $rel);
        return $this->done('تم تعيين الموسيقى الجديدة', '/admin/settings');
    }

    public function musicRemove(): Response
    {
        $this->authorize('settings.manage');
        MediaService::deleteFile((string) Settings::get('music.track', ''));
        Settings::set('music.track', '');
        return $this->done('تمت العودة إلى الموسيقى الافتراضية', '/admin/settings');
    }
}
