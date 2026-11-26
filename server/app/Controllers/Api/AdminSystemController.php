<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\DB;
use App\Core\FileStreamer;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Mailer;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\View;
use App\Services\ActivityLog;
use App\Services\AdminSettings;
use App\Services\AuthService;
use App\Services\BackupService;
use App\Services\FileService;
use App\Services\GameService;
use App\Services\MediaService;
use App\Services\NotificationService;
use App\Services\Presenter;
use App\Services\Queue;
use App\Services\Settings;
use App\Services\SystemService;
use App\Services\UploadService;
use App\Services\UserService;

/**
 * Admin API for everything the web panel can do beyond the catalogue itself:
 * settings, roles, tokens, files, storage, logs, backups, queue, bulk actions.
 * Used by the Android admin app; every action re-checks the caller's permission.
 */
final class AdminSystemController extends ApiController
{
    // ------------------------------------------------------------------ settings
    /** GET /api/v1/admin/settings — the groups this account may edit, with values and rules. */
    public function settings(): Response
    {
        $groups = AdminSettings::readable();
        if (!$groups) {
            throw new HttpException(403);
        }
        $extra = [];
        if (Auth::can('settings.manage')) {
            $track = (string) Settings::get('music.track', '');
            $extra['music_track'] = $track !== '' ? $track : null;
            $extra['mail_driver_active'] = Mailer::config()['driver'];
        }
        if (Auth::can('security.manage')) {
            $extra['disks'] = array_keys(FileService::disks());
        }
        return $this->ok(['groups' => $groups] + $extra);
    }

    /** PUT /api/v1/admin/settings/{group} — partial update: only the keys sent are validated and saved. */
    public function saveSettings(string $group): Response
    {
        $saved = AdminSettings::save($group, $this->request->all(), true);
        return $this->ok(['saved' => $saved]);
    }

    public function mailTest(): Response
    {
        $this->authorize('settings.manage');
        $u = $this->requireUser();
        $html = View::render('emails/action', ['heading' => 'رسالة اختبار', 'intro' => 'إعدادات البريد تعمل بشكل صحيح ✓',
            'action' => 'فتح المكتبة', 'link' => abs_url('/'), 'note' => 'أرسلت من تطبيق الإدارة.'], 'emails/layout');
        if (!Mailer::send($u['email'], 'اختبار البريد — ' . Settings::get('site.name'), $html)) {
            throw new HttpException(502, 'فشل الإرسال، راجع سجل الأخطاء لمعرفة السبب', [], 'mail_failed');
        }
        return $this->ok(['sent_to' => $u['email'], 'driver' => Mailer::config()['driver']]);
    }

    public function musicRemove(): Response
    {
        $this->authorize('settings.manage');
        MediaService::deleteFile((string) Settings::get('music.track', ''));
        Settings::set('music.track', '');
        return $this->ok(['music_track' => null]);
    }

    // ------------------------------------------------------------------ roles & users
    public function roles(): Response
    {
        $this->authorize('roles.assign');
        $m = UserService::permissionMatrix();
        $map = [];
        foreach ($m['roles'] as $r) {
            $rid = (int) $r['id'];
            $map[(string) $rid] = $r['slug'] === 'super_admin'
                ? array_map(static fn ($p) => (int) $p['id'], $m['permissions'])
                : array_map('intval', array_keys($m['map'][$rid] ?? []));
        }
        return $this->ok([
            'roles' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'level' => (int) $r['level'],
                'editable' => $r['slug'] !== 'super_admin'], $m['roles']),
            'permissions' => array_map(static fn ($p) => ['id' => (int) $p['id'], 'slug' => $p['slug'], 'name' => $p['name'], 'group' => $p['group_name']], $m['permissions']),
            'matrix' => $map,
            'can_edit' => (Auth::user()['role_slug'] ?? '') === 'super_admin',
        ]);
    }

    /** PUT /api/v1/admin/roles {"matrix": {"2": [1,2,3], "3": [...]}} — Super Admin only. */
    public function saveRoles(): Response
    {
        if ((Auth::user()['role_slug'] ?? '') !== 'super_admin') {
            throw new HttpException(403, 'تعديل مصفوفة الصلاحيات للمدير الرئيسي فقط');
        }
        $in = $this->request->input('matrix');
        if (!is_array($in)) {
            throw ValidationException::single('matrix', 'صيغة المصفوفة غير صحيحة');
        }
        $matrix = [];
        foreach ($in as $rid => $perms) {
            foreach ((array) $perms as $pid) {
                $matrix[(int) $rid][(int) $pid] = true;
            }
        }
        UserService::savePermissionMatrix($matrix);
        return $this->roles();
    }

    /** POST /api/v1/admin/users {username, email, password, role_id} */
    public function createUser(): Response
    {
        $this->authorize('users.manage');
        $roleId = $this->request->int('role_id', 4);
        $level = (int) DB::value('SELECT level FROM roles WHERE id = ?', [$roleId]);
        $actor = $this->requireUser();
        if (!$level || ($roleId !== 4 && !Auth::can('roles.assign')) || ($actor['role_slug'] !== 'super_admin' && $level >= (int) $actor['role_level'])) {
            throw new HttpException(403, 'لا يمكنك إنشاء حساب بهذا الدور');
        }
        $id = UserService::create($this->request->all(), $roleId, true);
        ActivityLog::log('user.register', 'user', $id, 'إنشاء حساب من تطبيق الإدارة');
        return $this->ok(Presenter::user(Auth::loadUser($id)), [], 201);
    }

    // ------------------------------------------------------------------ API tokens
    public function tokens(): Response
    {
        $this->authorize('api.manage');
        $rows = DB::all('SELECT t.id, t.name, t.last_used_at, t.last_used_ip, t.created_at, t.expires_at, u.id AS user_id, u.username
            FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > ?)
            ORDER BY t.last_used_at DESC LIMIT 300', [now()]);
        return $this->ok(array_map(static fn ($t) => [
            'id' => (int) $t['id'], 'name' => $t['name'], 'user' => ['id' => (int) $t['user_id'], 'username' => $t['username']],
            'last_used_at' => iso_date($t['last_used_at']), 'last_used_ip' => $t['last_used_ip'],
            'created_at' => iso_date($t['created_at']), 'expires_at' => iso_date($t['expires_at']),
            'is_current' => $t['id'] == Auth::tokenId(),
        ], $rows));
    }

    public function revokeToken(string $id): Response
    {
        $this->authorize('api.manage');
        if (!AuthService::revokeToken((int) $id)) {
            throw new HttpException(404, 'الرمز غير موجود');
        }
        return $this->ok(['revoked' => true]);
    }

    // ------------------------------------------------------------------ security overview
    public function security(): Response
    {
        $this->authorize('security.manage');
        $locked = DB::all('SELECT id, username, email, locked_until FROM users WHERE locked_until > ? ORDER BY locked_until DESC', [now()]);
        $failed = DB::all("SELECT a.action, a.description, a.ip, a.created_at, u.username FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id
            WHERE a.action IN ('auth.failed','auth.locked') ORDER BY a.id DESC LIMIT 30");
        $req = $this->request;
        return $this->ok([
            'https' => $req->isSecure(),
            'locked_users' => array_map(static fn ($u) => ['id' => (int) $u['id'], 'username' => $u['username'], 'email' => $u['email'], 'locked_until' => iso_date($u['locked_until'])], $locked),
            'recent_failures' => array_map(static fn ($a) => ['action' => $a['action'], 'description' => $a['description'], 'ip' => $a['ip'],
                'user' => $a['username'], 'at' => iso_date($a['created_at'])], $failed),
            'health' => SystemService::health(true),
        ]);
    }

    // ------------------------------------------------------------------ files, storage, queue
    /** GET /api/v1/admin/files?q=&type=&game_id= — every hosted file. */
    public function files(): Response
    {
        $this->authorize('files.manage');
        $user = Auth::user();
        return $this->paginated(FileService::adminList($this->request->query, $this->page(), $this->perPage(40)), static fn ($f) =>
            Presenter::file($f, $user, true) + ['game' => ['id' => (int) $f['game_id'], 'title' => $f['game_title'], 'slug' => $f['game_slug']]]);
    }

    public function orphans(): Response
    {
        $this->authorize('storage.manage');
        return $this->ok(array_map(static fn ($o) => $o + ['size_human' => human_bytes((int) $o['size'])], SystemService::orphans()));
    }

    /** POST /api/v1/admin/storage/{cleanup|orphans|cache} */
    public function storageAction(string $action): Response
    {
        $this->authorize('storage.manage');
        switch ($action) {
            case 'cleanup':
                $n = UploadService::cleanupStale(0);
                ActivityLog::log('storage.cleanup', 'storage', null, "تنظيف {$n} عملية رفع/أجزاء");
                return $this->ok(['cleaned_uploads' => $n]);
            case 'orphans':
                return $this->ok(SystemService::deleteOrphans());
            case 'cache':
                Cache::flush();
                Settings::forget();
                ActivityLog::log('cache.cleared', 'cache', null, 'مسح الكاش');
                return $this->ok(['cleared' => true, 'driver' => Cache::driver()]);
        }
        throw new HttpException(404);
    }

    public function runQueue(): Response
    {
        $this->authorize('files.upload', 'settings.manage');
        $r = Queue::work(25, 50);
        return $this->ok(['done' => (int) $r['done'], 'failed' => (int) $r['failed'], 'cleaned_uploads' => UploadService::cleanupStale(), 'queue' => Queue::stats()]);
    }

    // ------------------------------------------------------------------ logs
    public function errors(): Response
    {
        $this->authorize('logs.view');
        $level = $this->request->str('level');
        $entries = Logger::tail(min(500, $this->perPage(200, 500)), in_array($level, ['error', 'warning', 'info'], true) ? $level : null);
        return $this->ok(array_map(static fn ($e) => [
            'at' => isset($e['t']) ? iso_date($e['t']) : null, 'level' => $e['level'] ?? null, 'message' => $e['msg'] ?? '',
            'context' => $e['ctx'] ?? null, 'path' => $e['path'] ?? null, 'method' => $e['method'] ?? null, 'user_id' => $e['uid'] ?? null,
        ], $entries));
    }

    // ------------------------------------------------------------------ backups
    public function backups(): Response
    {
        $this->authorize('backups.manage');
        return $this->ok(array_map(static fn ($b) => $b + ['size_human' => human_bytes((int) $b['size']), 'created_at' => iso_date($b['created_at'])], BackupService::list()), [
            'frequency' => Settings::get('backups.frequency'), 'keep' => (int) Settings::get('backups.keep'), 'last_run' => Settings::get('backups.last_run'),
            'can_restore' => Auth::can('backups.restore'),
        ]);
    }

    public function createBackup(): Response
    {
        $this->authorize('backups.manage');
        $name = BackupService::create($this->request->bool('include_media'), 'app');
        return $this->ok(['name' => $name, 'size' => filesize(BackupService::path($name))], [], 201);
    }

    public function downloadBackup(string $name): Response
    {
        $this->authorize('backups.manage');
        return FileStreamer::response($this->request, BackupService::path($name), $name, 'application/octet-stream');
    }

    /** POST /api/v1/admin/backups/{name}/restore {password, with_media} — the account password is required again. */
    public function restoreBackup(string $name): Response
    {
        $this->authorize('backups.restore');
        $user = $this->requireUser();
        $hash = (string) DB::value('SELECT password_hash FROM users WHERE id = ?', [(int) $user['id']]);
        if (!password_verify((string) $this->request->input('password', ''), $hash)) {
            throw ValidationException::single('password', 'كلمة المرور غير صحيحة، لم تتم الاستعادة');
        }
        $safety = BackupService::restore($name, $this->request->bool('with_media', true));
        return $this->ok(['restored' => $name, 'safety_backup' => $safety]);
    }

    public function deleteBackup(string $name): Response
    {
        $this->authorize('backups.manage');
        BackupService::delete($name);
        return $this->ok(['deleted' => true]);
    }

    // ------------------------------------------------------------------ catalogue extras
    /** POST /api/v1/admin/games/bulk {ids: [...], action: publish|hide|feature|unfeature|delete} */
    public function bulkGames(): Response
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->request->input('ids', [])))));
        $action = (string) $this->request->input('action', '');
        if (!$ids) {
            throw ValidationException::single('ids', 'اختر لعبة واحدة على الأقل');
        }
        $perm = match ($action) {
            'publish', 'hide', 'feature', 'unfeature' => 'games.publish',
            'delete' => 'games.delete',
            default => throw ValidationException::single('action', 'إجراء غير معروف'),
        };
        $this->authorize($perm);
        $done = 0;
        $missing = [];
        foreach ($ids as $id) {
            try {
                match ($action) {
                    'publish', 'hide' => GameService::setPublished($id, $action === 'publish'),
                    'feature', 'unfeature' => GameService::save($id, ['is_featured' => $action === 'feature'], true, false),
                    'delete' => GameService::delete($id),
                };
                $done++;
            } catch (HttpException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
                $missing[] = $id;
            }
        }
        return $this->ok(['action' => $action, 'done' => $done, 'missing' => $missing]);
    }

    public function reorderScreenshots(string $id): Response
    {
        $this->authorize('media.upload');
        GameService::findOrFail((int) $id);
        GameService::reorderScreenshots((int) $id, array_map('intval', (array) $this->request->input('ids', [])));
        return $this->ok(['saved' => true]);
    }

    public function removeTrailer(string $id): Response
    {
        $this->authorize('games.edit');
        $g = GameService::findOrFail((int) $id);
        MediaService::deleteFile($g['trailer_url']);
        DB::update('games', ['trailer_url' => null, 'updated_at' => now()], 'id = ?', [(int) $id]);
        Cache::bump('games');
        ActivityLog::log('game.updated', 'game', (int) $id, 'إزالة الفيديو التعريفي: ' . $g['title']);
        return $this->ok(['removed' => true]);
    }

    public function deleteNotification(string $id): Response
    {
        $this->authorize('notifications.send');
        NotificationService::delete((int) $id);
        return $this->ok(['deleted' => true]);
    }
}
