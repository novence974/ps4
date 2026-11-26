<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Cache;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\CatalogService;
use App\Services\FileService;
use App\Services\GameService;
use App\Services\UpdateService;

/** Public pages: home, platforms, browse/search, game details, categories, updates. */
final class SiteController extends Controller
{
    private function favIds(array ...$lists): array
    {
        $ids = [];
        foreach ($lists as $list) {
            foreach ($list as $g) {
                $ids[] = (int) $g['id'];
            }
        }
        return GameService::favoriteIds(Auth::id(), array_values(array_unique($ids)));
    }

    public function home(): Response
    {
        $home = GameService::home();
        $hero = array_slice($home['featured'] ?: array_slice($home['top_rated'], 0, 3), 0, 5);
        return view('pages/home', [
            'home' => $home,
            'hero' => $hero,
            'platforms' => CatalogService::platforms(),
            'categories' => CatalogService::categories(),
            'updates' => UpdateService::list(1, 4)['items'],
            'favIds' => $this->favIds($home['featured'], $home['recent'], $home['top_rated'], $home['popular']),
        ]);
    }

    public function platforms(): Response
    {
        return view('pages/platforms', ['platforms' => CatalogService::platforms(), 'title' => 'أجهزة PlayStation']);
    }

    public function platform(string $slug): Response
    {
        $platform = CatalogService::platform($slug);
        if (!$platform || (!$platform['is_active'] && !Auth::can('platforms.manage'))) {
            throw new HttpException(404, 'الجهاز غير موجود');
        }
        $filters = ['platform' => $platform['slug']] + $this->request->query;
        // static export (tools/export_static.sh) runs on the built-in server and needs the whole catalogue on one page
        $perPage = PHP_SAPI === 'cli-server' && getenv('UPSL_STATIC_EXPORT') === '1' ? 1000 : (int) setting('site.per_page', 24);
        $result = GameService::search($filters, $this->page(), $perPage);
        if ($this->request->query('partial')) {
            return $this->gridFragment($result);
        }
        $stats = Cache::remember(Cache::key('games', 'platform-stats:' . $platform['id']), 300, static fn () => DB::one(
            'SELECT COUNT(*) AS total, AVG(rating) AS avg_rating, MIN(release_year) AS first_year, MAX(release_year) AS last_year,
                    SUM(files_status = \'available\') AS available
               FROM games WHERE platform_id = ? AND is_published = 1', [(int) $platform['id']]));
        return view('pages/platform', [
            'platform' => $platform, 'result' => $result, 'stats' => $stats, 'filters' => $result['filters'],
            'categories' => CatalogService::categories(), 'facets' => $this->facets(),
            'favIds' => $this->favIds($result['items']), 'title' => $platform['name'],
        ]);
    }

    private function facets(): array
    {
        return Cache::remember(Cache::key('games', 'facets'), 600, static fn () => [
            'years' => array_map('intval', DB::column('SELECT DISTINCT release_year FROM games WHERE is_published = 1 AND release_year IS NOT NULL ORDER BY release_year DESC')),
            'companies' => DB::column("SELECT name FROM (SELECT developer AS name FROM games WHERE is_published = 1 AND developer IS NOT NULL
                UNION SELECT publisher FROM games WHERE is_published = 1 AND publisher IS NOT NULL) t ORDER BY name LIMIT 300"),
        ]);
    }

    private function gridFragment(array $result): Response
    {
        $html = partial('game-grid', ['games' => $result['items'], 'favIds' => $this->favIds($result['items'])]);
        return Response::json(['ok' => true, 'html' => $html, 'page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total']]);
    }

    public function browse(?array $category = null): Response
    {
        $filters = $this->request->query;
        if ($category) {
            $filters['category'] = $category['slug'];
        }
        // static export (tools/export_static.sh) runs on the built-in server and needs the whole catalogue on one page
        $perPage = PHP_SAPI === 'cli-server' && getenv('UPSL_STATIC_EXPORT') === '1' ? 1000 : (int) setting('site.per_page', 24);
        $result = GameService::search($filters, $this->page(), $perPage);
        if ($this->request->query('partial')) {
            return $this->gridFragment($result);
        }
        return view('pages/browse', [
            'result' => $result, 'filters' => $result['filters'], 'category' => $category,
            'platforms' => CatalogService::platforms(), 'categories' => CatalogService::categories(),
            'facets' => $this->facets(), 'favIds' => $this->favIds($result['items']),
            'title' => $category ? $category['name'] : (!empty($result['filters']['q']) ? 'نتائج البحث: ' . $result['filters']['q'] : 'تصفح المكتبة'),
        ]);
    }

    public function categories(): Response
    {
        return view('pages/categories', ['categories' => CatalogService::categories(), 'title' => 'التصنيفات']);
    }

    public function category(string $slug): Response
    {
        $cat = CatalogService::category($slug) ?? throw new HttpException(404, 'التصنيف غير موجود');
        return $this->browse($cat);
    }

    public function game(string $slug): Response
    {
        $game = GameService::find($slug);
        $preview = false;
        if (!$game && Auth::can('games.edit')) {
            $game = GameService::find($slug, true);
            $preview = (bool) $game;
        }
        if (!$game) {
            throw new HttpException(404, 'اللعبة غير موجودة أو غير منشورة');
        }
        GameService::trackView((int) $game['id'], Auth::id(), $this->request->ip());
        $user = Auth::user();
        foreach ($game['files'] as &$f) {
            $f['game_published'] = $game['is_published'];
            $f['deny_reason'] = FileService::denyReason($user, $f);
        }
        unset($f);
        $related = GameService::related($game);
        return view('pages/game', [
            'game' => $game, 'preview' => $preview, 'related' => $related,
            'isFavorite' => in_array((int) $game['id'], GameService::favoriteIds(Auth::id(), [(int) $game['id']]), true),
            'favIds' => $this->favIds($related), 'trailer' => GameService::trailerEmbed($game['trailer_url']),
            'title' => $game['title'] . ' — ' . $game['platform_short'],
            'metaDescription' => mb_substr(strip_tags((string) $game['description']), 0, 160),
        ]);
    }

    public function updates(): Response
    {
        $type = (string) $this->request->query('type', '');
        return view('pages/updates', ['result' => UpdateService::list($this->page(), 20, false, $type ?: null), 'type' => $type, 'title' => 'التحديثات']);
    }
}
