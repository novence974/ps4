<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\ActivityLog;
use App\Services\AuthService;
use App\Services\CatalogService;
use App\Services\FileService;
use App\Services\GameService;
use App\Services\ImportService;
use App\Services\NotificationService;
use App\Services\Presenter;
use App\Services\SystemService;
use App\Services\UpdateService;
use App\Services\UserService;

/** Management API used by the Android manager app (all routes require admin.access + a specific permission). */
final class AdminController extends ApiController
{
    // ------------------------------------------------------------------ dashboard
    public function stats(): Response
    {
        return $this->ok(SystemService::dashboard());
    }

    public function health(): Response
    {
        return $this->ok(SystemService::health(true));
    }

    // ------------------------------------------------------------------ games
    public function games(): Response
    {
        $r = GameService::search($this->request->query, $this->page(), $this->perPage(30), true);
        return $this->paginated($r, static fn ($g) => Presenter::gameSummary($g));
    }

    public function game(string $id): Response
    {
        return $this->ok(Presenter::gameDetail(GameService::findOrFail((int) $id), Auth::user(), true));
    }

    public function createGame(): Response
    {
        $this->authorize('games.create');
        $g = GameService::save(null, $this->request->all());
        return $this->ok(Presenter::gameDetail($g, Auth::user(), true), [], 201);
    }

    public function updateGame(string $id): Response
    {
        $this->authorize('games.edit');
        $g = GameService::save((int) $id, $this->request->all(), true);
        return $this->ok(Presenter::gameDetail($g, Auth::user(), true));
    }

    public function deleteGame(string $id): Response
    {
        $this->authorize('games.delete');
        GameService::delete((int) $id);
        return $this->ok(['deleted' => true, 'id' => (int) $id]);
    }

    public function publish(string $id): Response
    {
        $this->authorize('games.publish');
        $g = GameService::setPublished((int) $id, $this->request->bool('published', true));
        return $this->ok(Presenter::gameSummary($g));
    }

    public function checkDuplicates(): Response
    {
        $this->authorize('games.create', 'games.edit');
        $title = $this->request->str('title');
        $pid = $this->request->int('platform_id');
        if ($title === '' || $pid <= 0) {
            return $this->ok([]);
        }
        return $this->ok(GameService::duplicates($title, $pid, $this->request->str('product_code') ?: null, $this->request->int('exclude_id') ?: null, $this->request->str('region') ?: null));
    }

    public function duplicates(): Response
    {
        $this->authorize('games.edit');
        return $this->ok(self::duplicateGroups());
    }

    public static function duplicateGroups(): array
    {
        $groups = DB::all("SELECT 'title' AS kind, g.platform_id, p.short_name, g.title_normalized AS ref, COUNT(*) AS c, GROUP_CONCAT(g.id ORDER BY g.id) AS ids
                FROM games g JOIN platforms p ON p.id = g.platform_id GROUP BY g.platform_id, p.short_name, g.title_normalized HAVING c > 1
             UNION ALL
             SELECT 'code', g.platform_id, p.short_name, g.product_code, COUNT(*) AS c, GROUP_CONCAT(g.id ORDER BY g.id)
                FROM games g JOIN platforms p ON p.id = g.platform_id WHERE g.product_code IS NOT NULL AND g.product_code <> ''
               GROUP BY g.platform_id, p.short_name, g.product_code HAVING c > 1 LIMIT 500");
        foreach ($groups as &$grp) {
            $ids = array_map('intval', explode(',', (string) $grp['ids']));
            [$in, $params] = DB::in($ids, 'd');
            $grp['games'] = DB::all("SELECT id, title, slug, region, product_code, release_year, is_published, created_at FROM games WHERE id IN ($in)", $params);
            unset($grp['ids']);
        }
        return $groups;
    }

    public function cover(string $id): Response
    {
        $this->authorize('media.upload');
        [$tmp] = $this->incomingImage();
        return $this->ok(GameService::setImage((int) $id, 'cover', $tmp));
    }

    public function banner(string $id): Response
    {
        $this->authorize('media.upload');
        [$tmp] = $this->incomingImage();
        return $this->ok(GameService::setImage((int) $id, 'banner', $tmp));
    }

    public function screenshot(string $id): Response
    {
        $this->authorize('media.upload');
        [$tmp] = $this->incomingImage();
        return $this->ok(GameService::addScreenshot((int) $id, $tmp, $this->request->str('caption') ?: null), [], 201);
    }

    public function deleteScreenshot(string $id, string $imageId): Response
    {
        $this->authorize('media.upload');
        GameService::deleteScreenshot((int) $id, (int) $imageId);
        return $this->ok(['deleted' => true]);
    }

    public function versions(string $id): Response
    {
        return $this->ok(array_map([Presenter::class, 'version'], GameService::findOrFail((int) $id)['versions']));
    }

    public function saveVersion(string $id, ?string $versionId = null): Response
    {
        $this->authorize('games.edit');
        $vid = GameService::saveVersion((int) $id, $versionId ? (int) $versionId : null, $this->request->all());
        return $this->ok(Presenter::version(DB::one('SELECT * FROM game_versions WHERE id = ?', [$vid])), [], $versionId ? 200 : 201);
    }

    public function deleteVersion(string $id, string $versionId): Response
    {
        $this->authorize('games.edit');
        GameService::deleteVersion((int) $id, (int) $versionId);
        return $this->ok(['deleted' => true]);
    }

    public function links(string $id): Response
    {
        return $this->ok(array_map([Presenter::class, 'link'], GameService::findOrFail((int) $id)['links']));
    }

    public function saveLink(string $id, ?string $linkId = null): Response
    {
        $this->authorize('links.manage');
        $lid = GameService::saveLink((int) $id, $linkId ? (int) $linkId : null, $this->request->all());
        return $this->ok(Presenter::link(DB::one('SELECT * FROM game_links WHERE id = ?', [$lid])), [], $linkId ? 200 : 201);
    }

    public function deleteLink(string $id, string $linkId): Response
    {
        $this->authorize('links.manage');
        GameService::deleteLink((int) $id, (int) $linkId);
        return $this->ok(['deleted' => true]);
    }

    public function saveCompat(string $id): Response
    {
        $this->authorize('games.edit');
        return $this->ok(['id' => GameService::saveCompat((int) $id, $this->request->all())]);
    }

    public function deleteCompat(string $id, string $compatId): Response
    {
        $this->authorize('games.edit');
        GameService::deleteCompat((int) $id, (int) $compatId);
        return $this->ok(['deleted' => true]);
    }

    public function files(string $id): Response
    {
        $g = GameService::findOrFail((int) $id);
        return $this->ok(array_map(static fn ($f) => Presenter::file($f + ['game_published' => $g['is_published']], Auth::user(), true), $g['files']));
    }

    public function updateFile(string $fileId): Response
    {
        $this->authorize('files.manage');
        return $this->ok(Presenter::file(FileService::update((int) $fileId, $this->request->all()), Auth::user(), true));
    }

    public function deleteFile(string $fileId): Response
    {
        $this->authorize('files.manage');
        FileService::delete((int) $fileId);
        return $this->ok(['deleted' => true]);
    }

    public function verifyFile(string $fileId): Response
    {
        $this->authorize('files.manage');
        return $this->ok(FileService::verifyIntegrity((int) $fileId));
    }

    /** POST /api/v1/admin/import  multipart "file" (CSV/JSON) or {token}; mode=preview|commit; on_duplicate=skip|update|create */
    public function import(): Response
    {
        $this->authorize('games.import');
        $token = $this->request->str('token');
        if ($token === '') {
            [$tmp, $name] = $this->uploadedTmp('file');
            $token = ImportService::store($tmp, $name);
        }
        $mode = $this->request->str('mode') === 'commit' ? 'commit' : 'preview';
        $res = ImportService::run($token, $mode, $this->request->str('on_duplicate') ?: 'skip');
        if ($mode === 'preview') {
            $res['token'] = $token;
            $res['rows'] = array_map(static function ($r) {
                unset($r['input']);
                return $r;
            }, $res['rows']);
        }
        return $this->ok($res);
    }

    // ------------------------------------------------------------------ platforms & categories
    public function platforms(): Response
    {
        return $this->ok(array_map([Presenter::class, 'platform'], CatalogService::platforms(false)));
    }

    public function savePlatform(?string $id = null): Response
    {
        $this->authorize('platforms.manage');
        $pid = CatalogService::savePlatform($id ? (int) $id : null, $this->request->all(), (bool) $id);
        return $this->ok(Presenter::platform(CatalogService::platform($pid)), [], $id ? 200 : 201);
    }

    public function platformImage(string $id, string $kind): Response
    {
        $this->authorize('platforms.manage');
        [$tmp] = $this->incomingImage();
        return $this->ok(CatalogService::setPlatformImage((int) $id, $kind === 'logo' ? 'logo' : 'image', $tmp));
    }

    public function saveCategory(?string $id = null): Response
    {
        $this->authorize('categories.manage');
        $cid = CatalogService::saveCategory($id ? (int) $id : null, $this->request->all(), (bool) $id);
        return $this->ok(Presenter::category(CatalogService::category($cid)), [], $id ? 200 : 201);
    }

    public function deleteCategory(string $id): Response
    {
        $this->authorize('categories.manage');
        CatalogService::deleteCategory((int) $id);
        return $this->ok(['deleted' => true]);
    }

    // ------------------------------------------------------------------ users
    public function users(): Response
    {
        $this->authorize('users.view', 'users.manage');
        return $this->paginated(UserService::list($this->request->query, $this->page(), $this->perPage(30)), static fn ($u) => Presenter::user($u, false) + [
            'email' => $u['email'], 'status' => $u['status'], 'email_verified' => !empty($u['email_verified_at']),
            'can_download' => (bool) $u['can_download'], 'downloads' => (int) $u['downloads_count'],
            'last_login_at' => iso_date($u['last_login_at']), 'locked' => $u['locked_until'] && strtotime($u['locked_until'] . ' UTC') > time(),
        ]);
    }

    public function showUser(string $id): Response
    {
        $this->authorize('users.view', 'users.manage');
        $u = Auth::loadUser((int) $id) ?? throw new HttpException(404, 'المستخدم غير موجود');
        $uid = (int) $id;
        $downloads = DB::all('SELECT d.created_at, d.ip, g.id AS game_id, g.title, f.original_name FROM downloads d JOIN games g ON g.id = d.game_id
            LEFT JOIN game_files f ON f.id = d.file_id WHERE d.user_id = ? ORDER BY d.id DESC LIMIT 15', [$uid]);
        $activity = DB::all('SELECT action, description, ip, created_at FROM activity_logs WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$uid]);
        return $this->ok(Presenter::user($u) + [
            'stats' => array_map('intval', UserService::profileStats($uid)),
            'locked' => !empty($u['locked_until']) && strtotime($u['locked_until'] . ' UTC') > time(),
            'last_login_ip' => $u['last_login_ip'] ?? null,
            'devices' => array_map(static fn ($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'last_used_at' => iso_date($t['last_used_at'] ?? null),
                'last_used_ip' => $t['last_used_ip'] ?? null], AuthService::userTokens($uid)),
            'recent_downloads' => array_map(static fn ($d) => ['at' => iso_date($d['created_at']), 'ip' => $d['ip'], 'game' => ['id' => (int) $d['game_id'], 'title' => $d['title']],
                'file' => $d['original_name']], $downloads),
            'recent_activity' => array_map(static fn ($a) => ['action' => $a['action'], 'label' => ActivityLog::label($a['action']), 'description' => $a['description'],
                'ip' => $a['ip'], 'at' => iso_date($a['created_at'])], $activity),
        ]);
    }

    public function updateUser(string $id): Response
    {
        $this->authorize('users.manage');
        return $this->ok(Presenter::user(UserService::adminUpdate($this->requireUser(), (int) $id, $this->request->all())));
    }

    public function deleteUser(string $id): Response
    {
        $this->authorize('users.manage');
        UserService::delete($this->requireUser(), (int) $id);
        return $this->ok(['deleted' => true]);
    }

    // ------------------------------------------------------------------ updates & notifications
    public function updates(): Response
    {
        $this->authorize('updates.manage');
        return $this->paginated(UpdateService::list($this->page(), $this->perPage(20), true, $this->request->str('type') ?: null), [Presenter::class, 'update']);
    }

    public function saveUpdate(?string $id = null): Response
    {
        $this->authorize('updates.manage');
        $uid = UpdateService::save($id ? (int) $id : null, $this->request->all());
        return $this->ok(Presenter::update(UpdateService::find($uid)), [], $id ? 200 : 201);
    }

    public function deleteUpdate(string $id): Response
    {
        $this->authorize('updates.manage');
        UpdateService::delete((int) $id);
        return $this->ok(['deleted' => true]);
    }

    public function notifications(): Response
    {
        $this->authorize('notifications.send');
        return $this->paginated(NotificationService::adminList($this->page(), $this->perPage(30)), static fn ($n) => Presenter::notification($n + ['is_read' => false]) + ['target' => $n['target'], 'sender' => $n['sender']]);
    }

    public function sendNotification(): Response
    {
        $this->authorize('notifications.send');
        return $this->ok(['id' => NotificationService::send($this->request->all(), (int) Auth::id())], [], 201);
    }

    // ------------------------------------------------------------------ logs & storage
    public function downloads(): Response
    {
        $this->authorize('downloads.view');
        $page = $this->page();
        $per = $this->perPage(50);
        $total = (int) DB::value('SELECT COUNT(*) FROM downloads');
        $rows = DB::all('SELECT d.id, d.created_at, d.ip, u.username, g.title, g.id AS game_id, f.original_name, f.size_bytes FROM downloads d
                LEFT JOIN users u ON u.id = d.user_id JOIN games g ON g.id = d.game_id LEFT JOIN game_files f ON f.id = d.file_id
                ORDER BY d.id DESC LIMIT :lim OFFSET :off', ['lim' => $per, 'off' => ($page - 1) * $per]);
        return $this->paginated(['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int) ceil($total / $per))], static fn ($d) => [
            'id' => (int) $d['id'], 'at' => iso_date($d['created_at']), 'user' => $d['username'], 'ip' => $d['ip'],
            'game' => ['id' => (int) $d['game_id'], 'title' => $d['title']], 'file' => $d['original_name'], 'size_bytes' => $d['size_bytes'] !== null ? (int) $d['size_bytes'] : null,
        ]);
    }

    public function storage(): Response
    {
        $this->authorize('storage.manage');
        return $this->ok(SystemService::storageOverview());
    }

    public function logs(): Response
    {
        $this->authorize('logs.view');
        return $this->paginated(ActivityLog::list($this->request->query, $this->page(), $this->perPage(50)), static fn ($l) => [
            'id' => (int) $l['id'], 'action' => $l['action'], 'label' => ActivityLog::label($l['action']), 'description' => $l['description'],
            'user' => $l['username'], 'ip' => $l['ip'], 'entity' => $l['entity_type'] ? ['type' => $l['entity_type'], 'id' => $l['entity_id'] !== null ? (int) $l['entity_id'] : null] : null,
            'at' => iso_date($l['created_at']),
        ]);
    }
}
