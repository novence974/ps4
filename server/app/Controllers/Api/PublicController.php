<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\CatalogService;
use App\Services\GameService;
use App\Services\Presenter;
use App\Services\SystemService;
use App\Services\UpdateService;

final class PublicController extends ApiController
{
    /** GET /api/v1/status — server health for the apps' "server status" screens. */
    public function status(): Response
    {
        return $this->ok(SystemService::health(Auth::can('admin.access')));
    }

    public function platforms(): Response
    {
        return $this->ok(array_map([Presenter::class, 'platform'], CatalogService::platforms()));
    }

    public function platform(string $slug): Response
    {
        $p = CatalogService::platform($slug);
        if (!$p || !$p['is_active']) {
            throw new HttpException(404, 'الجهاز غير موجود');
        }
        return $this->ok(Presenter::platform($p));
    }

    public function categories(): Response
    {
        return $this->ok(array_map([Presenter::class, 'category'], CatalogService::categories()));
    }

    /** GET /api/v1/games — every browse/search filter, sort and pagination option. */
    public function games(): Response
    {
        $result = GameService::search($this->request->query, $this->page(), $this->perPage(24, 100));
        $fav = GameService::favoriteIds(Auth::id(), array_column($result['items'], 'id'));
        return $this->paginated($result, static fn ($g) => Presenter::gameSummary($g, $fav), ['filters' => (object) $result['filters']]);
    }

    /** GET /api/v1/search?q=…  (suggest=1 returns a compact list for type-ahead). */
    public function search(): Response
    {
        $q = $this->request->str('q');
        if (mb_strlen($q) < 1) {
            throw \App\Core\ValidationException::single('q', 'اكتب كلمة للبحث');
        }
        if ($this->request->bool('suggest')) {
            $result = GameService::search(['q' => $q] + $this->request->query, 1, 8);
            // absolute URLs like the rest of the API (the apps use this list for type-ahead too); cover_jpg for clients without WebP
            return $this->ok(array_map(static fn ($g) => [
                'id' => (int) $g['id'], 'title' => $g['title'], 'slug' => $g['slug'], 'platform' => $g['platform_short'],
                'year' => $g['release_year'] ? (int) $g['release_year'] : null,
                'cover' => $g['cover_path'] ? media_url($g['cover_path'], 'sm', 'webp', true) : abs_url('assets/img/brand/cover-placeholder.svg'),
                'cover_jpg' => $g['cover_path'] ? media_url($g['cover_path'], 'sm', 'jpg', true) : null,
                'url' => abs_url('game/' . $g['slug']),
            ], $result['items']), ['total' => $result['total']]);
        }
        return $this->games();
    }

    public function game(string $id): Response
    {
        $g = GameService::find($id) ?? throw new HttpException(404, 'اللعبة غير موجودة');
        GameService::trackView((int) $g['id'], Auth::id(), $this->request->ip());
        return $this->ok(Presenter::gameDetail($g, Auth::user()));
    }

    public function related(string $id): Response
    {
        $g = GameService::find($id) ?? throw new HttpException(404, 'اللعبة غير موجودة');
        $rows = GameService::related($g);
        $fav = GameService::favoriteIds(Auth::id(), array_column($rows, 'id'));
        return $this->ok(array_map(static fn ($r) => Presenter::gameSummary($r, $fav), $rows));
    }

    public function updates(): Response
    {
        $type = $this->request->str('type');
        return $this->paginated(UpdateService::list($this->page(), $this->perPage(20, 50), false, $type ?: null), [Presenter::class, 'update']);
    }
}
