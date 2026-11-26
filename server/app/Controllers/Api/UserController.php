<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\AuthService;
use App\Services\FileService;
use App\Services\GameService;
use App\Services\NotificationService;
use App\Services\Presenter;
use App\Services\UpdateService;
use App\Services\UserService;

final class UserController extends ApiController
{
    public function profile(): Response
    {
        $u = $this->requireUser();
        return $this->ok(Presenter::user($u) + ['stats' => array_map('intval', UserService::profileStats((int) $u['id'])),
            'unread_notifications' => NotificationService::unreadCount($u)]);
    }

    public function updateProfile(): Response
    {
        $u = UserService::updateProfile($this->requireUser(), $this->request->all());
        Auth::set($u, Auth::via(), Auth::tokenId());
        return $this->ok(Presenter::user($u));
    }

    public function avatar(): Response
    {
        $u = $this->requireUser();
        [$tmp] = $this->uploadedTmp('file');
        return $this->ok(UserService::setAvatar((int) $u['id'], $tmp));
    }

    public function password(): Response
    {
        AuthService::changePassword($this->requireUser(), $this->request->all());
        return $this->ok(['message' => 'تم تغيير كلمة المرور، وتم إلغاء جلسات الأجهزة الأخرى']);
    }

    public function preferences(): Response
    {
        return $this->ok(UserService::savePreferences((int) $this->requireUser()['id'], $this->request->all()));
    }

    public function favorites(): Response
    {
        $u = $this->requireUser();
        $r = GameService::favorites((int) $u['id'], $this->page(), $this->perPage(24));
        $ids = array_map('intval', array_column($r['items'], 'id'));
        return $this->paginated($r, static fn ($g) => Presenter::gameSummary($g, $ids) + ['favorited_at' => iso_date($g['activity_at'])]);
    }

    public function addFavorite(): Response
    {
        $u = $this->requireUser();
        $gid = $this->request->int('game_id');
        if ($gid <= 0) {
            throw \App\Core\ValidationException::single('game_id', 'رقم اللعبة مطلوب');
        }
        GameService::setFavorite((int) $u['id'], $gid, true);
        return $this->ok(['game_id' => $gid, 'favorite' => true, 'count' => (int) DB::value('SELECT favorite_count FROM games WHERE id = ?', [$gid])], [], 201);
    }

    public function removeFavorite(string $gameId): Response
    {
        $u = $this->requireUser();
        GameService::setFavorite((int) $u['id'], (int) $gameId, false);
        return $this->ok(['game_id' => (int) $gameId, 'favorite' => false]);
    }

    public function downloads(): Response
    {
        $u = $this->requireUser();
        return $this->paginated(FileService::userDownloads((int) $u['id'], $this->page(), $this->perPage(30)), static fn ($d) => [
            'id' => (int) $d['id'], 'downloaded_at' => iso_date($d['created_at']),
            'game' => ['id' => (int) $d['game_id'], 'title' => $d['title'], 'slug' => $d['slug'], 'platform' => $d['platform_short'], 'cover' => cover_url($d, 'sm')],
            'file' => $d['file_id'] ? ['id' => (int) $d['file_id'], 'name' => $d['original_name'], 'type' => $d['file_type'], 'size_bytes' => (int) $d['size_bytes'], 'sha256' => $d['sha256']] : null,
        ]);
    }

    public function history(): Response
    {
        $u = $this->requireUser();
        $r = GameService::history((int) $u['id'], $this->page(), $this->perPage(24));
        $fav = GameService::favoriteIds((int) $u['id'], array_column($r['items'], 'id'));
        return $this->paginated($r, static fn ($g) => Presenter::gameSummary($g, $fav) + ['viewed_at' => iso_date($g['activity_at'])]);
    }

    public function clearHistory(): Response
    {
        GameService::clearHistory((int) $this->requireUser()['id']);
        return $this->ok(['message' => 'تم مسح السجل']);
    }

    public function updates(): Response
    {
        $u = $this->requireUser();
        return $this->paginated(UpdateService::forUser((int) $u['id'], $this->page(), $this->perPage(20)), [Presenter::class, 'update']);
    }

    public function notifications(): Response
    {
        $u = $this->requireUser();
        $r = NotificationService::forUser($u, $this->page(), $this->perPage(30));
        return $this->paginated($r, [Presenter::class, 'notification'], ['unread' => $r['unread']]);
    }

    public function readNotifications(): Response
    {
        $u = $this->requireUser();
        $id = $this->request->int('id');
        NotificationService::markRead($u, $id ?: null);
        return $this->ok(['unread' => NotificationService::unreadCount(Auth::loadUser((int) $u['id']))]);
    }

    public function tokens(): Response
    {
        $u = $this->requireUser();
        return $this->ok(array_map(static fn ($t) => [
            'id' => (int) $t['id'], 'name' => $t['name'], 'current' => (int) $t['id'] === Auth::tokenId(),
            'last_used_at' => iso_date($t['last_used_at']), 'last_used_ip' => $t['last_used_ip'],
            'expires_at' => iso_date($t['expires_at']), 'created_at' => iso_date($t['created_at']),
        ], AuthService::userTokens((int) $u['id'])));
    }

    public function revokeToken(string $id): Response
    {
        if (!AuthService::revokeToken((int) $id, (int) $this->requireUser()['id'])) {
            throw new HttpException(404, 'الرمز غير موجود');
        }
        return $this->ok(['revoked' => true]);
    }

    /** POST /api/v1/games/{id}/files/{fileId}/link — authorises a download and returns a signed URL. */
    public function downloadLink(string $gameId, string $fileId): Response
    {
        $file = FileService::find((int) $fileId);
        if (!$file || (int) $file['game_id'] !== (int) $gameId) {
            throw new HttpException(404, 'الملف غير موجود');
        }
        return $this->ok(FileService::issueLink($file, Auth::user(), true));
    }
}
