<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Str;
use App\Core\ValidationException;
use App\Core\Validator;

final class GameService
{
    public const SORTS = ['newest', 'title', 'downloads', 'rating', 'year', 'updated', 'relevance'];
    public const REGIONS = ['GLOBAL', 'EUR', 'USA', 'JPN', 'ASIA', 'MENA'];

    private const LIST_COLUMNS = 'g.id, g.platform_id, g.title, g.slug, g.product_code, g.release_year, g.developer, g.publisher,
        g.age_rating, g.size_bytes, g.languages, g.region, g.version, g.edition, g.rating, g.cover_path, g.banner_path, g.files_status,
        g.is_published, g.is_featured, g.published_at, g.view_count, g.download_count, g.favorite_count, g.created_at, g.updated_at,
        p.slug AS platform_slug, p.name AS platform_name, p.short_name AS platform_short, p.accent_color AS platform_color';

    // ================================================================ listing & search

    public static function normalizeFilters(array $in): array
    {
        $f = [];
        foreach (['q', 'platform', 'category', 'company', 'developer', 'publisher', 'language', 'region', 'availability', 'sort', 'order'] as $k) {
            if (isset($in[$k]) && is_scalar($in[$k]) && trim((string) $in[$k]) !== '') {
                $f[$k] = mb_substr(trim((string) $in[$k]), 0, 100);
            }
        }
        foreach (['year', 'year_from', 'year_to', 'added_within', 'published'] as $k) {
            if (isset($in[$k]) && is_numeric($in[$k])) {
                $f[$k] = (int) $in[$k];
            }
        }
        foreach (['size_min', 'size_max', 'rating_min'] as $k) {
            if (isset($in[$k]) && is_numeric($in[$k]) && (float) $in[$k] >= 0) {
                $f[$k] = (float) $in[$k];
            }
        }
        if (!empty($in['featured'])) {
            $f['featured'] = 1;
        }
        if (!empty($in['updated_since']) && is_scalar($in['updated_since'])) {
            $ts = is_numeric($in['updated_since']) ? (int) $in['updated_since'] : strtotime((string) $in['updated_since']);
            if ($ts) {
                $f['updated_since'] = gmdate('Y-m-d H:i:s', $ts);
            }
        }
        if (isset($f['sort']) && !in_array($f['sort'], self::SORTS, true)) {
            unset($f['sort']);
        }
        if (isset($f['order']) && !in_array($f['order'], ['asc', 'desc'], true)) {
            unset($f['order']);
        }
        if (isset($f['availability']) && !in_array($f['availability'], ['available', 'unavailable', 'coming_soon'], true)) {
            unset($f['availability']);
        }
        return $f;
    }

    private static function escLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }

    /** @return array{0:array,1:array,2:string,3:array,4:string} where, params, orderBy, orderParams, join */
    private static function buildQuery(array $f, bool $admin): array
    {
        $w = [];
        $p = [];
        $op = [];
        $join = '';
        if (!$admin) {
            $w[] = 'g.is_published = 1';
        } elseif (isset($f['published'])) {
            $w[] = 'g.is_published = :pub';
            $p['pub'] = $f['published'] ? 1 : 0;
        }
        if (!empty($f['platform'])) {
            $plat = CatalogService::platform($f['platform']);
            $w[] = 'g.platform_id = :pid';
            $p['pid'] = $plat ? (int) $plat['id'] : 0;
        }
        if (!empty($f['category'])) {
            $cat = CatalogService::category($f['category']);
            $w[] = 'EXISTS (SELECT 1 FROM game_categories gc WHERE gc.game_id = g.id AND gc.category_id = :cid)';
            $p['cid'] = $cat ? (int) $cat['id'] : 0;
        }
        if (!empty($f['q'])) {
            $q = $f['q'];
            $clean = trim((string) preg_replace('/[+\-<>()~*"@]+/u', ' ', $q));
            $terms = array_values(array_filter(preg_split('/\s+/u', $clean) ?: [], static fn ($t) => mb_strlen($t) >= 3));
            $parts = [];
            if ($terms) {
                $parts[] = 'SELECT id FROM games WHERE MATCH(title, developer, publisher) AGAINST (:ft IN BOOLEAN MODE)';
                $p['ft'] = implode(' ', array_map(static fn ($t) => '+' . $t . '*', array_slice($terms, 0, 8)));
            }
            $parts[] = 'SELECT id FROM games WHERE title LIKE :qp';
            $p['qp'] = self::escLike($q) . '%';
            $parts[] = 'SELECT id FROM games WHERE product_code = :qc';
            $p['qc'] = $q;
            $norm = Str::normalizeTitle($q);
            if ($norm !== '') {
                $parts[] = 'SELECT id FROM games WHERE title_normalized LIKE :qn';
                $p['qn'] = '%' . self::escLike($norm) . '%';
            }
            // A derived table is materialised once; "g.id IN (… UNION …)" would run as a dependent subquery per row.
            $join = 'JOIN (' . implode(' UNION ', $parts) . ') sm ON sm.id = g.id';
        }
        if (!empty($f['year'])) { $w[] = 'g.release_year = :yr'; $p['yr'] = $f['year']; }
        if (!empty($f['year_from'])) { $w[] = 'g.release_year >= :yf'; $p['yf'] = $f['year_from']; }
        if (!empty($f['year_to'])) { $w[] = 'g.release_year <= :yt'; $p['yt'] = $f['year_to']; }
        if (!empty($f['company'])) {
            $w[] = '(g.developer LIKE :co1 OR g.publisher LIKE :co2)';
            $p['co1'] = $p['co2'] = self::escLike($f['company']) . '%';
        }
        if (!empty($f['developer'])) { $w[] = 'g.developer = :dev'; $p['dev'] = $f['developer']; }
        if (!empty($f['publisher'])) { $w[] = 'g.publisher = :pubr'; $p['pubr'] = $f['publisher']; }
        if (!empty($f['language'])) { $w[] = 'FIND_IN_SET(:lang, g.languages) > 0'; $p['lang'] = strtolower($f['language']); }
        if (!empty($f['region'])) { $w[] = 'g.region = :reg'; $p['reg'] = strtoupper($f['region']); }
        if (isset($f['size_min'])) { $w[] = 'g.size_bytes >= :smin'; $p['smin'] = (int) round($f['size_min'] * 1024 ** 3); }
        if (isset($f['size_max'])) { $w[] = 'g.size_bytes <= :smax'; $p['smax'] = (int) round($f['size_max'] * 1024 ** 3); }
        if (isset($f['rating_min'])) { $w[] = 'g.rating >= :rmin'; $p['rmin'] = $f['rating_min']; }
        if (!empty($f['added_within'])) { $w[] = 'g.created_at >= :since'; $p['since'] = gmdate('Y-m-d H:i:s', time() - $f['added_within'] * 86400); }
        if (!empty($f['availability'])) { $w[] = 'g.files_status = :fs'; $p['fs'] = $f['availability']; }
        if (!empty($f['featured'])) { $w[] = 'g.is_featured = 1'; }
        if (!empty($f['updated_since'])) { $w[] = 'g.updated_at > :us'; $p['us'] = $f['updated_since']; }

        $sort = $f['sort'] ?? (!empty($f['q']) ? 'relevance' : 'newest');
        $asc = ($f['order'] ?? '') === 'asc';
        $desc = ($f['order'] ?? '') === 'desc';
        $orderBy = match ($sort) {
            'title' => 'g.title ' . ($desc ? 'DESC' : 'ASC') . ', g.id',
            'downloads' => 'g.download_count ' . ($asc ? 'ASC' : 'DESC') . ', g.id DESC',
            'rating' => 'g.rating IS NULL, g.rating ' . ($asc ? 'ASC' : 'DESC') . ', g.download_count DESC, g.id DESC',
            'year' => 'g.release_year IS NULL, g.release_year ' . ($asc ? 'ASC' : 'DESC') . ', g.id DESC',
            'updated' => 'g.updated_at DESC, g.id DESC',
            'relevance' => !empty($f['q']) ? '(g.title LIKE :rel) DESC, g.download_count DESC, g.id DESC' : 'g.created_at DESC, g.id DESC',
            default => 'g.created_at ' . ($asc ? 'ASC' : 'DESC') . ', g.id ' . ($asc ? 'ASC' : 'DESC'),
        };
        if ($sort === 'relevance' && !empty($f['q'])) {
            $op['rel'] = self::escLike($f['q']) . '%';
        }
        return [$w, $p, $orderBy, $op, $join];
    }

    public static function search(array $filters, int $page = 1, int $perPage = 24, bool $admin = false): array
    {
        $f = self::normalizeFilters($filters);
        $page = max(1, min(10000, $page));
        $perPage = max(1, min(100, $perPage));
        $key = Cache::key('games', 'search:' . md5(json_encode([$f, $page, $perPage, $admin])));
        $cached = $admin ? null : Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        [$where, $params, $orderBy, $orderParams, $join] = self::buildQuery($f, $admin);
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $limits = ['lim' => $perPage, 'off' => ($page - 1) * $perPage];
        // Sort and paginate on ids only (narrow rows sort in memory), then load the full rows for that page.
        if ($join !== '') {
            // text search: the matched set is materialised once and counted in the same pass
            $hits = DB::all("SELECT g.id, COUNT(*) OVER () AS total_rows FROM games g {$join} {$w} ORDER BY {$orderBy} LIMIT :lim OFFSET :off", $params + $orderParams + $limits);
            $total = $hits ? (int) $hits[0]['total_rows'] : ($page > 1 ? (int) DB::value("SELECT COUNT(*) FROM games g {$join} {$w}", $params) : 0);
            $ids = array_map('intval', array_column($hits, 'id'));
        } else {
            $total = (int) DB::value("SELECT COUNT(*) FROM games g {$w}", $params);
            $ids = $total === 0 ? [] : array_map('intval', DB::column("SELECT g.id FROM games g {$w} ORDER BY {$orderBy} LIMIT :lim OFFSET :off", $params + $orderParams + $limits));
        }
        $rows = self::listByIds($ids);
        $result = ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage)), 'filters' => $f];
        if (!$admin) {
            Cache::set($key, $result, 120);
        }
        return $result;
    }

    private static function listWhere(string $condition, string $order, int $limit): array
    {
        return self::listByIds(array_map('intval', DB::column("SELECT g.id FROM games g WHERE g.is_published = 1 AND {$condition} ORDER BY {$order} LIMIT {$limit}")));
    }

    /** Full list rows (with platform + categories) for the given ids, kept in the given order. */
    public static function listByIds(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        [$in, $params] = DB::in($ids, 'id');
        $byId = [];
        foreach (DB::all('SELECT ' . self::LIST_COLUMNS . " FROM games g JOIN platforms p ON p.id = g.platform_id WHERE g.id IN ($in)", $params) as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $rows = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $rows[] = $byId[$id];
            }
        }
        self::attachCategories($rows);
        return $rows;
    }

    public static function home(): array
    {
        return Cache::remember(Cache::key('games', 'home'), 120, static fn () => [
            'featured' => self::listWhere('g.is_featured = 1', 'g.updated_at DESC', 6),
            'recent' => self::listWhere('1=1', 'g.created_at DESC, g.id DESC', 12),
            'top_rated' => self::listWhere('g.rating IS NOT NULL', 'g.rating DESC, g.download_count DESC', 12),
            'popular' => self::listWhere('1=1', 'g.download_count DESC, g.view_count DESC', 12),
        ]);
    }

    public static function related(array $game, int $limit = 8): array
    {
        $catIds = array_column($game['categories'] ?? [], 'id');
        return Cache::remember(Cache::key('games', 'related:' . $game['id']), 300, static function () use ($game, $catIds, $limit) {
            $params = ['pid' => (int) $game['platform_id'], 'gid' => (int) $game['id']];
            $cond = 'g.platform_id = :pid AND g.id <> :gid';
            if ($catIds) {
                [$in, $ip] = DB::in($catIds, 'c');
                $cond .= " AND EXISTS (SELECT 1 FROM game_categories gc WHERE gc.game_id = g.id AND gc.category_id IN ($in))";
                $params += $ip;
            }
            return self::listByIds(array_map('intval', DB::column("SELECT g.id FROM games g
                WHERE g.is_published = 1 AND {$cond} ORDER BY g.rating DESC, g.download_count DESC LIMIT " . (int) $limit, $params)));
        });
    }

    public static function attachCategories(array &$rows): void
    {
        if (!$rows) {
            return;
        }
        [$in, $params] = DB::in(array_column($rows, 'id'), 'g');
        $map = [];
        foreach (DB::all("SELECT gc.game_id, c.id, c.slug, c.name, c.color FROM game_categories gc
                JOIN categories c ON c.id = gc.category_id WHERE gc.game_id IN ($in) ORDER BY c.sort_order", $params) as $r) {
            $map[(int) $r['game_id']][] = ['id' => (int) $r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'color' => $r['color']];
        }
        foreach ($rows as &$row) {
            $row['categories'] = $map[(int) $row['id']] ?? [];
        }
    }

    public static function favoriteIds(?int $userId, array $gameIds): array
    {
        if (!$userId || !$gameIds) {
            return [];
        }
        [$in, $params] = DB::in($gameIds, 'f');
        return array_map('intval', DB::column("SELECT game_id FROM favorites WHERE user_id = :uid AND game_id IN ($in)", $params + ['uid' => $userId]));
    }

    // ================================================================ single game

    public static function find(int|string $idOrSlug, bool $admin = false): ?array
    {
        $byId = is_int($idOrSlug) || ctype_digit((string) $idOrSlug);
        $g = DB::one(
            'SELECT g.*, p.slug AS platform_slug, p.name AS platform_name, p.short_name AS platform_short, p.accent_color AS platform_color,
                    p.logo_path AS platform_logo, p.supported_formats AS platform_formats, p.compatibility_notes AS platform_compat,
                    p.install_notes AS platform_install
               FROM games g JOIN platforms p ON p.id = g.platform_id
              WHERE ' . ($byId ? 'g.id' : 'g.slug') . ' = ?' . ($admin ? '' : ' AND g.is_published = 1'),
            [$byId ? (int) $idOrSlug : (string) $idOrSlug]
        );
        if (!$g) {
            return null;
        }
        $rows = [$g];
        self::attachCategories($rows);
        $g = $rows[0];
        $id = (int) $g['id'];
        $g['images'] = DB::all('SELECT * FROM game_images WHERE game_id = ? ORDER BY sort_order, id', [$id]);
        $g['versions'] = DB::all('SELECT * FROM game_versions WHERE game_id = ? ORDER BY is_latest DESC, COALESCE(release_date, DATE(created_at)) DESC, id DESC', [$id]);
        $g['files'] = DB::all(
            "SELECT f.*, v.version AS version_name FROM game_files f LEFT JOIN game_versions v ON v.id = f.version_id
              WHERE f.game_id = ?" . ($admin ? '' : ' AND f.is_active = 1') . "
              ORDER BY FIELD(f.file_type, 'game', 'update', 'dlc', 'data', 'save', 'other'), f.created_at DESC",
            [$id]
        );
        $g['links'] = DB::all('SELECT * FROM game_links WHERE game_id = ?' . ($admin ? '' : ' AND is_active = 1') . ' ORDER BY sort_order, id', [$id]);
        $g['compat'] = DB::all('SELECT * FROM game_compatibility WHERE game_id = ? ORDER BY target', [$id]);
        return $g;
    }

    public static function findOrFail(int $id): array
    {
        return self::find($id, true) ?? throw new HttpException(404, 'اللعبة غير موجودة');
    }

    // ================================================================ create / update / delete

    public static function rules(): array
    {
        return [
            'platform_id' => 'required|int|exists:platforms,id',
            'title' => 'required|string|max:190',
            'product_code' => 'nullable|string|max:32|regex:/^[A-Za-z0-9_\-.]+$/',
            'description' => 'nullable|string|max:20000',
            'release_year' => 'nullable|year',
            'developer' => 'nullable|string|max:128',
            'publisher' => 'nullable|string|max:128',
            'age_rating' => 'nullable|string|max:24',
            'size' => 'nullable|string|max:40',
            'languages' => 'nullable',
            'region' => 'nullable|in:' . implode(',', self::REGIONS),
            'version' => 'nullable|string|max:32',
            'edition' => 'nullable|string|max:64',
            'rating' => 'nullable|numeric|between:0,10',
            'trailer_url' => 'nullable|string|max:500',
            'files_status' => 'nullable|in:available,unavailable,coming_soon',
            'compatibility_notes' => 'nullable|string|max:5000',
            'is_published' => 'bool',
            'is_featured' => 'bool',
            'categories' => 'nullable',
        ];
    }

    public static function labels(): array
    {
        return [
            'platform_id' => 'الجهاز', 'title' => 'اسم اللعبة', 'product_code' => 'رمز المنتج', 'description' => 'الوصف',
            'release_year' => 'سنة الإصدار', 'developer' => 'المطور', 'publisher' => 'الناشر', 'age_rating' => 'التصنيف العمري',
            'size' => 'الحجم', 'region' => 'المنطقة', 'version' => 'الإصدار', 'edition' => 'النسخة', 'rating' => 'التقييم',
            'trailer_url' => 'رابط الفيديو', 'compatibility_notes' => 'ملاحظات التوافق',
        ];
    }

    public static function normalizeLanguages(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        $list = is_array($value) ? $value : preg_split('/[\s,|;]+/', (string) $value);
        $codes = [];
        foreach ($list as $code) {
            $code = strtolower(trim((string) $code));
            if (preg_match('/^[a-z]{2,3}$/', $code)) {
                $codes[] = $code;
            }
        }
        $codes = array_values(array_unique($codes));
        return $codes ? implode(',', array_slice($codes, 0, 30)) : null;
    }

    public static function validateTrailer(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (preg_match('#^media/trailers/\d{4}/\d{2}/[a-f0-9]{20}\.(mp4|webm)$#', $url)) {
            return $url;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            throw ValidationException::single('trailer_url', 'رابط الفيديو يجب أن يكون رابط YouTube أو Vimeo أو ملف MP4 يبدأ بـ https');
        }
        return $url;
    }

    /** @return array{type:string,src:string}|null */
    public static function trailerEmbed(?string $url): ?array
    {
        if (!$url) {
            return null;
        }
        if (str_starts_with($url, 'media/')) {
            return ['type' => 'video', 'src' => url($url)];
        }
        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
            return ['type' => 'youtube', 'src' => 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?autoplay=1&rel=0', 'id' => $m[1]];
        }
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
            return ['type' => 'vimeo', 'src' => 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1'];
        }
        if (preg_match('~\.(mp4|webm)(\?.*)?$~i', $url)) {
            return ['type' => 'video', 'src' => $url];
        }
        return ['type' => 'link', 'src' => $url];
    }

    private static function uniqueSlug(string $title, int $platformId): string
    {
        $plat = CatalogService::platform($platformId);
        $base = Str::slug($title, 160) . '-' . ($plat['slug'] ?? 'ps');
        $slug = $base;
        $i = 2;
        while (DB::value('SELECT 1 FROM games WHERE slug = ?', [$slug])) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    public static function duplicates(string $title, int $platformId, ?string $productCode = null, ?int $excludeId = null, ?string $region = null, bool $exactOnly = false): array
    {
        $norm = Str::normalizeTitle($title);
        if ($norm === '' && !$productCode) {
            return [];
        }
        $params = ['p' => $platformId, 'ex' => $excludeId ?? 0, 'n' => $norm];
        $sql = 'SELECT id, title, slug, region, product_code, is_published, release_year FROM games
                 WHERE platform_id = :p AND id <> :ex AND (title_normalized = :n';
        if ($productCode) {
            $sql .= ' OR product_code = :pc';
            $params['pc'] = $productCode;
        }
        $rows = DB::all($sql . ') LIMIT 20', $params);
        $exact = [];
        foreach ($rows as $r) {
            $sameCode = $productCode && $r['product_code'] && strcasecmp($r['product_code'], $productCode) === 0;
            if ($sameCode || !$region || !$r['region'] || $r['region'] === $region) {
                $r['match'] = 'exact';
                $exact[] = $r;
            }
        }
        if ($exactOnly) {
            return $exact;
        }
        $prefix = mb_substr($norm, 0, 4);
        if (mb_strlen($prefix) >= 3) {
            $ids = array_column($exact, 'id');
            foreach (DB::all('SELECT id, title, slug, region, product_code, is_published, release_year, title_normalized FROM games
                    WHERE platform_id = :p AND id <> :ex AND title_normalized LIKE :pre LIMIT 100',
                ['p' => $platformId, 'ex' => $excludeId ?? 0, 'pre' => self::escLike($prefix) . '%']) as $c) {
                if (in_array($c['id'], $ids, true)) {
                    continue;
                }
                similar_text($norm, $c['title_normalized'], $pct);
                if ($pct >= 80) {
                    unset($c['title_normalized']);
                    $c['match'] = 'similar';
                    $c['similarity'] = (int) round($pct);
                    $exact[] = $c;
                }
            }
        }
        return $exact;
    }

    public static function save(?int $id, array $input, bool $partial = false, bool $reload = true): array
    {
        $existing = $id ? (DB::one('SELECT * FROM games WHERE id = ?', [$id]) ?? throw new HttpException(404, 'اللعبة غير موجودة')) : null;
        if (isset($input['size_bytes']) && !isset($input['size']) && is_numeric($input['size_bytes'])) {
            $input['size'] = (string) (int) $input['size_bytes'];
        }
        if (isset($input['categories']) && is_string($input['categories'])) {
            $input['categories'] = preg_split('/[,|]+/', $input['categories']);
        }
        $data = Validator::validate($input, self::rules(), self::labels(), $partial);
        $row = [];
        foreach (['platform_id', 'title', 'product_code', 'description', 'release_year', 'developer', 'publisher', 'age_rating', 'region', 'version', 'edition', 'rating', 'compatibility_notes'] as $k) {
            if (array_key_exists($k, $data)) {
                $row[$k] = $data[$k] === '' ? null : $data[$k];
            }
        }
        if (array_key_exists('size', $data)) {
            $bytes = Str::parseSize($data['size']);
            if ($data['size'] !== null && $bytes === null) {
                throw ValidationException::single('size', 'صيغة الحجم غير صحيحة، أمثلة: 45.2 GB أو 700 MB');
            }
            $row['size_bytes'] = $bytes;
        }
        if (array_key_exists('languages', $data)) {
            $row['languages'] = self::normalizeLanguages($data['languages']);
        }
        if (array_key_exists('trailer_url', $data)) {
            $row['trailer_url'] = self::validateTrailer($data['trailer_url']);
        }
        if (!empty($data['files_status'])) {
            $row['files_status'] = $data['files_status'];
        }
        if (isset($row['title'])) {
            $row['title_normalized'] = Str::normalizeTitle($row['title']);
        }
        if (Auth::can('games.publish')) {
            foreach (['is_published', 'is_featured'] as $k) {
                if (array_key_exists($k, $data)) {
                    $row[$k] = $data[$k] ? 1 : 0;
                }
            }
        }

        $platformId = (int) ($row['platform_id'] ?? $existing['platform_id']);
        $title = (string) ($row['title'] ?? $existing['title']);
        $code = array_key_exists('product_code', $row) ? $row['product_code'] : ($existing['product_code'] ?? null);
        $region = array_key_exists('region', $row) ? $row['region'] : ($existing['region'] ?? null);
        $identityChanged = !$existing
            || Str::normalizeTitle($title) !== $existing['title_normalized']
            || $platformId !== (int) $existing['platform_id']
            || $code !== $existing['product_code'] || $region !== $existing['region'];
        if ($identityChanged && empty($input['allow_duplicate'])) {
            $dups = self::duplicates($title, $platformId, $code, $id, $region, true);
            if ($dups) {
                throw new DuplicateGameException($dups);
            }
        }

        $newlyPublished = false;
        $id = DB::transaction(static function () use ($existing, $id, $row, $data, $title, $platformId, &$newlyPublished) {
            if ($existing) {
                $row['updated_at'] = now();
                $row['updated_by'] = Auth::id();
                if (($row['is_published'] ?? 0) === 1 && !$existing['published_at']) {
                    $row['published_at'] = now();
                    $newlyPublished = true;
                }
                DB::update('games', $row, 'id = ?', [$id]);
            } else {
                $row['slug'] = self::uniqueSlug($title, $platformId);
                $row['created_by'] = $row['updated_by'] = Auth::id();
                $row['created_at'] = $row['updated_at'] = now();
                $row['files_status'] ??= 'unavailable';
                if (!empty($row['is_published'])) {
                    $row['published_at'] = now();
                    $newlyPublished = true;
                }
                $id = DB::insert('games', $row);
            }
            if (array_key_exists('categories', $data)) {
                self::syncCategories($id, (array) ($data['categories'] ?? []));
            }
            return $id;
        });

        Cache::bump('games');
        ActivityLog::log($existing ? 'game.updated' : 'game.created', 'game', $id, ($existing ? 'تعديل لعبة: ' : 'إضافة لعبة: ') . $title);
        if ($newlyPublished) {
            UpdateService::autoForGame($id, 'game_added');
        }
        return $reload ? self::findOrFail($id) : ['id' => $id];
    }

    public static function syncCategories(int $gameId, array $values): void
    {
        $ids = CatalogService::resolveCategoryIds($values);
        DB::delete('game_categories', 'game_id = ?', [$gameId]);
        foreach ($ids as $cid) {
            DB::insert('game_categories', ['game_id' => $gameId, 'category_id' => $cid], true);
        }
    }

    public static function setPublished(int $id, bool $published): array
    {
        $g = DB::one('SELECT * FROM games WHERE id = ?', [$id]) ?? throw new HttpException(404, 'اللعبة غير موجودة');
        $upd = ['is_published' => $published ? 1 : 0, 'updated_at' => now(), 'updated_by' => Auth::id()];
        $first = $published && !$g['published_at'];
        if ($first) {
            $upd['published_at'] = now();
        }
        DB::update('games', $upd, 'id = ?', [$id]);
        Cache::bump('games');
        ActivityLog::log($published ? 'game.published' : 'game.hidden', 'game', $id, ($published ? 'نشر: ' : 'إخفاء: ') . $g['title']);
        if ($first) {
            UpdateService::autoForGame($id, 'game_added');
        }
        return self::findOrFail($id);
    }

    public static function delete(int $id): void
    {
        $g = DB::one('SELECT * FROM games WHERE id = ?', [$id]) ?? throw new HttpException(404, 'اللعبة غير موجودة');
        foreach (DB::all('SELECT * FROM game_files WHERE game_id = ?', [$id]) as $f) {
            FileService::unlinkPhysical($f);
        }
        foreach (DB::column('SELECT path FROM game_images WHERE game_id = ?', [$id]) as $p) {
            MediaService::deleteImage($p);
        }
        MediaService::deleteImage($g['cover_path']);
        MediaService::deleteImage($g['banner_path']);
        MediaService::deleteFile($g['trailer_url']);
        // unfinished uploads aimed at this game would have nowhere to go: drop their chunks
        foreach (DB::all("SELECT id, uuid FROM uploads WHERE game_id = ? AND status IN ('uploading', 'failed')", [$id]) as $up) {
            UploadService::removeDir(UploadService::chunksRoot() . '/' . $up['uuid']);
            DB::update('uploads', ['status' => 'cancelled', 'updated_at' => now()], 'id = ?', [(int) $up['id']]);
            DB::delete('upload_chunks', 'upload_id = ?', [(int) $up['id']]);
        }
        DB::delete('games', 'id = ?', [$id]);
        Cache::bump('games');
        ActivityLog::log('game.deleted', 'game', $id, 'حذف لعبة: ' . $g['title']);
    }

    // ================================================================ media

    /** An image link from the internet, kept exactly as given: the server never downloads or changes it. */
    public static function validImageUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($url === '' || strlen($url) > 255 || !$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\s"\'<>]/', $url)) {
            throw ValidationException::single('url', 'أدخل رابط صورة يبدأ بـ https:// (حتى 255 حرفاً)');
        }
        return $url;
    }

    public static function setImageUrl(int $id, string $kind, string $url): array
    {
        $g = DB::one('SELECT id, title, cover_path, banner_path FROM games WHERE id = ?', [$id]) ?? throw new HttpException(404, 'اللعبة غير موجودة');
        $purpose = $kind === 'banner' ? 'banner' : 'cover';
        $url = self::validImageUrl($url);
        $col = $purpose . '_path';
        MediaService::deleteImage($g[$col]);
        DB::update('games', [$col => $url, 'updated_at' => now()], 'id = ?', [$id]);
        Cache::bump('games');
        ActivityLog::log('game.media', 'game', $id, ($purpose === 'cover' ? 'رابط الغلاف: ' : 'رابط الخلفية: ') . $g['title']);
        return ['path' => $url, 'urls' => MediaService::urls($url, $purpose)];
    }

    public static function addScreenshotUrl(int $id, string $url, ?string $caption = null): array
    {
        if (!DB::value('SELECT 1 FROM games WHERE id = ?', [$id])) {
            throw new HttpException(404, 'اللعبة غير موجودة');
        }
        if ((int) DB::value('SELECT COUNT(*) FROM game_images WHERE game_id = ?', [$id]) >= 40) {
            throw ValidationException::single('url', 'الحد الأقصى 40 صورة لكل لعبة');
        }
        $url = self::validImageUrl($url);
        $imageId = DB::insert('game_images', [
            'game_id' => $id, 'path' => $url, 'width' => 0, 'height' => 0,
            'caption' => $caption !== null ? mb_substr($caption, 0, 190) : null,
            'sort_order' => (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM game_images WHERE game_id = ?', [$id]),
            'created_at' => now(),
        ]);
        Cache::bump('games');
        return ['id' => $imageId, 'caption' => $caption, 'width' => 0, 'height' => 0] + (MediaService::urls($url, 'screenshot') ?? []);
    }

    public static function setImage(int $id, string $kind, string $src): array
    {
        $g = DB::one('SELECT id, title, cover_path, banner_path FROM games WHERE id = ?', [$id]) ?? throw new HttpException(404, 'اللعبة غير موجودة');
        $purpose = $kind === 'banner' ? 'banner' : 'cover';
        $stored = MediaService::storeImage($src, $purpose);
        $col = $purpose . '_path';
        MediaService::deleteImage($g[$col]);
        DB::update('games', [$col => $stored['path'], 'updated_at' => now()], 'id = ?', [$id]);
        Cache::bump('games');
        ActivityLog::log('game.media', 'game', $id, ($purpose === 'cover' ? 'تحديث الغلاف: ' : 'تحديث الخلفية: ') . $g['title']);
        return ['path' => $stored['path'], 'urls' => MediaService::urls($stored['path'], $purpose)];
    }

    public static function addScreenshot(int $id, string $src, ?string $caption = null): array
    {
        if (!DB::value('SELECT 1 FROM games WHERE id = ?', [$id])) {
            throw new HttpException(404, 'اللعبة غير موجودة');
        }
        if ((int) DB::value('SELECT COUNT(*) FROM game_images WHERE game_id = ?', [$id]) >= 40) {
            throw ValidationException::single('file', 'الحد الأقصى 40 صورة لكل لعبة');
        }
        $stored = MediaService::storeImage($src, 'screenshot');
        $imageId = DB::insert('game_images', [
            'game_id' => $id, 'path' => $stored['path'], 'width' => min(65535, $stored['width']), 'height' => min(65535, $stored['height']),
            'caption' => $caption !== null ? mb_substr($caption, 0, 190) : null,
            'sort_order' => (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM game_images WHERE game_id = ?', [$id]),
            'created_at' => now(),
        ]);
        DB::update('games', ['updated_at' => now()], 'id = ?', [$id]);
        Cache::bump('games');
        return ['id' => $imageId, 'path' => $stored['path'], 'urls' => MediaService::urls($stored['path'], 'screenshot')];
    }

    public static function deleteScreenshot(int $gameId, int $imageId): void
    {
        $img = DB::one('SELECT * FROM game_images WHERE id = ? AND game_id = ?', [$imageId, $gameId]) ?? throw new HttpException(404, 'الصورة غير موجودة');
        MediaService::deleteImage($img['path']);
        DB::delete('game_images', 'id = ?', [$imageId]);
        Cache::bump('games');
    }

    public static function reorderScreenshots(int $gameId, array $ids): void
    {
        foreach (array_values($ids) as $i => $imageId) {
            DB::update('game_images', ['sort_order' => $i + 1], 'id = ? AND game_id = ?', [(int) $imageId, $gameId]);
        }
        Cache::bump('games');
    }

    public static function setTrailerFile(int $id, string $src, string $originalName): string
    {
        $g = DB::one('SELECT trailer_url FROM games WHERE id = ?', [$id]) ?? throw new HttpException(404, 'اللعبة غير موجودة');
        $rel = MediaService::storeFile($src, 'trailer', $originalName);
        MediaService::deleteFile($g['trailer_url']);
        DB::update('games', ['trailer_url' => $rel, 'updated_at' => now()], 'id = ?', [$id]);
        Cache::bump('games');
        return $rel;
    }

    // ================================================================ versions / links / compatibility

    public static function saveVersion(int $gameId, ?int $versionId, array $input): int
    {
        $g = DB::one('SELECT id, title FROM games WHERE id = ?', [$gameId]) ?? throw new HttpException(404, 'اللعبة غير موجودة');
        $d = Validator::validate($input, [
            'version' => 'required|string|max:32', 'region' => 'nullable|in:' . implode(',', self::REGIONS),
            'release_date' => 'nullable|date', 'size' => 'nullable|string|max:40', 'changelog' => 'nullable|string|max:10000',
            'is_latest' => 'bool', 'notify' => 'bool',
        ], ['version' => 'رقم الإصدار', 'release_date' => 'تاريخ الإصدار', 'changelog' => 'سجل التغييرات']);
        $row = ['version' => $d['version'], 'region' => $d['region'] ?: null, 'release_date' => $d['release_date'] ?: null,
            'size_bytes' => Str::parseSize($d['size'] ?? null), 'changelog' => $d['changelog'] ?: null,
            'is_latest' => $d['is_latest'] ? 1 : 0, 'updated_at' => now()];
        if ($d['is_latest']) {
            DB::run('UPDATE game_versions SET is_latest = 0 WHERE game_id = ?', [$gameId]);
        }
        if ($versionId) {
            if (!DB::update('game_versions', $row, 'id = ? AND game_id = ?', [$versionId, $gameId]) && !DB::value('SELECT 1 FROM game_versions WHERE id = ? AND game_id = ?', [$versionId, $gameId])) {
                throw new HttpException(404, 'الإصدار غير موجود');
            }
        } else {
            $row['game_id'] = $gameId;
            $row['created_at'] = now();
            $versionId = DB::insert('game_versions', $row);
            if ($d['notify']) {
                UpdateService::save(null, [
                    'type' => 'patch', 'game_id' => $gameId, 'version_id' => $versionId, 'is_published' => true, 'notify_favorites' => true,
                    'title' => 'إصدار ' . $d['version'] . ' متاح للعبة ' . $g['title'], 'body' => $d['changelog'] ?? '',
                ]);
            }
        }
        $gameUpd = ['updated_at' => now()];
        if ($d['is_latest']) {
            $gameUpd['version'] = $d['version'];
        }
        DB::update('games', $gameUpd, 'id = ?', [$gameId]);
        Cache::bump('games');
        ActivityLog::log('game.updated', 'game', $gameId, 'إصدار ' . $d['version'] . ' — ' . $g['title']);
        return $versionId;
    }

    public static function deleteVersion(int $gameId, int $versionId): void
    {
        if (!DB::delete('game_versions', 'id = ? AND game_id = ?', [$versionId, $gameId])) {
            throw new HttpException(404, 'الإصدار غير موجود');
        }
        Cache::bump('games');
    }

    public static function saveLink(int $gameId, ?int $linkId, array $input): int
    {
        if (!DB::value('SELECT 1 FROM games WHERE id = ?', [$gameId])) {
            throw new HttpException(404, 'اللعبة غير موجودة');
        }
        $d = Validator::validate($input, [
            'title' => 'required|string|max:190', 'url' => 'required|url|max:1000',
            'link_type' => 'required|in:official_store,publisher,authorized_mirror,info',
            'region' => 'nullable|in:' . implode(',', self::REGIONS), 'sort_order' => 'nullable|int', 'is_active' => 'bool',
        ], ['title' => 'عنوان الرابط', 'url' => 'الرابط', 'link_type' => 'نوع الرابط']);
        $row = ['title' => $d['title'], 'url' => $d['url'], 'link_type' => $d['link_type'], 'region' => $d['region'] ?: null,
            'sort_order' => (int) ($d['sort_order'] ?? 0), 'is_active' => array_key_exists('is_active', $input) ? ($d['is_active'] ? 1 : 0) : 1, 'updated_at' => now()];
        if ($linkId) {
            if (!DB::value('SELECT 1 FROM game_links WHERE id = ? AND game_id = ?', [$linkId, $gameId])) {
                throw new HttpException(404, 'الرابط غير موجود');
            }
            DB::update('game_links', $row, 'id = ?', [$linkId]);
        } else {
            $row += ['game_id' => $gameId, 'created_at' => now()];
            $linkId = DB::insert('game_links', $row);
        }
        DB::update('games', ['updated_at' => now()], 'id = ?', [$gameId]);
        Cache::bump('games');
        return $linkId;
    }

    public static function deleteLink(int $gameId, int $linkId): void
    {
        if (!DB::delete('game_links', 'id = ? AND game_id = ?', [$linkId, $gameId])) {
            throw new HttpException(404, 'الرابط غير موجود');
        }
        Cache::bump('games');
    }

    public static function saveCompat(int $gameId, array $input): int
    {
        if (!DB::value('SELECT 1 FROM games WHERE id = ?', [$gameId])) {
            throw new HttpException(404, 'اللعبة غير موجودة');
        }
        $d = Validator::validate($input, [
            'target' => 'required|string|max:64', 'status' => 'required|in:' . implode(',', array_keys(compat_names())),
            'notes' => 'nullable|string|max:500', 'tested_at' => 'nullable|date',
        ], ['target' => 'الجهاز/المحاكي', 'status' => 'الحالة']);
        DB::run('INSERT INTO game_compatibility (game_id, target, status, notes, tested_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), notes = VALUES(notes), tested_at = VALUES(tested_at), updated_at = VALUES(updated_at)',
            [$gameId, $d['target'], $d['status'], $d['notes'] ?: null, $d['tested_at'] ?: null, now(), now()]);
        Cache::bump('games');
        return (int) DB::value('SELECT id FROM game_compatibility WHERE game_id = ? AND target = ?', [$gameId, $d['target']]);
    }

    public static function deleteCompat(int $gameId, int $id): void
    {
        DB::delete('game_compatibility', 'id = ? AND game_id = ?', [$id, $gameId]);
        Cache::bump('games');
    }

    public static function refreshFileStatus(int $gameId): void
    {
        $g = DB::one('SELECT files_status, size_bytes FROM games WHERE id = ?', [$gameId]);
        if (!$g) {
            return;
        }
        $count = (int) DB::value('SELECT COUNT(*) FROM game_files WHERE game_id = ? AND is_active = 1', [$gameId]);
        $status = $count > 0 ? 'available' : ($g['files_status'] === 'coming_soon' ? 'coming_soon' : 'unavailable');
        $upd = ['files_status' => $status, 'updated_at' => now()];
        if (!$g['size_bytes']) {
            $sum = DB::value("SELECT SUM(size_bytes) FROM game_files WHERE game_id = ? AND is_active = 1 AND file_type = 'game'", [$gameId]);
            if ($sum) {
                $upd['size_bytes'] = (int) $sum;
            }
        }
        DB::update('games', $upd, 'id = ?', [$gameId]);
        Cache::bump('games');
    }

    // ================================================================ user interactions

    public static function trackView(int $gameId, ?int $userId, string $ip): void
    {
        try {
            if (Cache::store()->increment('gv:' . $gameId . ':' . ($userId ? 'u' . $userId : $ip), 1800) === 1) {
                DB::run('UPDATE games SET view_count = view_count + 1 WHERE id = ?', [$gameId]);
            }
        } catch (\Throwable) {
        }
        if ($userId) {
            DB::run('INSERT INTO view_history (user_id, game_id, views, viewed_at) VALUES (?, ?, 1, ?)
                     ON DUPLICATE KEY UPDATE views = views + 1, viewed_at = VALUES(viewed_at)', [$userId, $gameId, now()]);
        }
    }

    public static function setFavorite(int $userId, int $gameId, bool $on): bool
    {
        if (!DB::value('SELECT 1 FROM games WHERE id = ? AND is_published = 1', [$gameId])) {
            throw new HttpException(404, 'اللعبة غير موجودة');
        }
        if ($on) {
            $n = DB::run('INSERT IGNORE INTO favorites (user_id, game_id, created_at) VALUES (?, ?, ?)', [$userId, $gameId, now()])->rowCount();
            if ($n) {
                DB::run('UPDATE games SET favorite_count = favorite_count + 1 WHERE id = ?', [$gameId]);
                if (\App\Core\Cache::store()->increment('favlog:' . $userId . ':' . $gameId, 600) === 1) ActivityLog::log('favorite.added', 'game', $gameId, (string) DB::value('SELECT title FROM games WHERE id = ?', [$gameId]), [], $userId);
            }
        } else {
            $n = DB::delete('favorites', 'user_id = ? AND game_id = ?', [$userId, $gameId]);
            if ($n) {
                DB::run('UPDATE games SET favorite_count = GREATEST(favorite_count, 1) - 1 WHERE id = ?', [$gameId]);
                if (\App\Core\Cache::store()->increment('favlog:' . $userId . ':' . $gameId, 600) === 1) ActivityLog::log('favorite.removed', 'game', $gameId, (string) DB::value('SELECT title FROM games WHERE id = ?', [$gameId]), [], $userId);
            }
        }
        return $on;
    }

    public static function toggleFavorite(int $userId, int $gameId): bool
    {
        $exists = (bool) DB::value('SELECT 1 FROM favorites WHERE user_id = ? AND game_id = ?', [$userId, $gameId]);
        return self::setFavorite($userId, $gameId, !$exists);
    }

    private static function userList(string $table, string $dateCol, int $userId, int $page, int $perPage): array
    {
        $total = (int) DB::value("SELECT COUNT(*) FROM {$table} t JOIN games g ON g.id = t.game_id WHERE t.user_id = ? AND g.is_published = 1", [$userId]);
        $rows = DB::all('SELECT ' . self::LIST_COLUMNS . ", t.{$dateCol} AS activity_at FROM {$table} t
                JOIN games g ON g.id = t.game_id JOIN platforms p ON p.id = g.platform_id
               WHERE t.user_id = :uid AND g.is_published = 1 ORDER BY t.{$dateCol} DESC LIMIT :lim OFFSET :off",
            ['uid' => $userId, 'lim' => $perPage, 'off' => ($page - 1) * $perPage]);
        self::attachCategories($rows);
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function favorites(int $userId, int $page = 1, int $perPage = 24): array
    {
        return self::userList('favorites', 'created_at', $userId, max(1, $page), $perPage);
    }

    public static function history(int $userId, int $page = 1, int $perPage = 24): array
    {
        return self::userList('view_history', 'viewed_at', $userId, max(1, $page), $perPage);
    }

    public static function clearHistory(int $userId): void
    {
        DB::delete('view_history', 'user_id = ?', [$userId]);
    }
}
