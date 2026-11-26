<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Validator;

/** Platforms (PS5…PS1) and categories. */
final class CatalogService
{
    // ------------------------------------------------------------------ platforms

    public static function platforms(bool $activeOnly = true): array
    {
        return Cache::remember(Cache::key('games', 'platforms:' . (int) $activeOnly), 300, static fn () => DB::all(
            'SELECT p.*, (SELECT COUNT(*) FROM games g WHERE g.platform_id = p.id AND g.is_published = 1) AS games_count
               FROM platforms p ' . ($activeOnly ? 'WHERE p.is_active = 1 ' : '') . 'ORDER BY p.sort_order, p.id'
        ));
    }

    public static function platform(string|int $idOrSlug): ?array
    {
        foreach (self::platforms(false) as $p) {
            if ((is_int($idOrSlug) || ctype_digit((string) $idOrSlug)) ? (int) $p['id'] === (int) $idOrSlug : $p['slug'] === $idOrSlug) {
                return $p;
            }
        }
        return null;
    }

    public static function platformRules(?int $id = null): array
    {
        return [
            'name' => 'required|string|max:64',
            'short_name' => 'required|string|max:16',
            'slug' => 'required|slug|max:32|unique:platforms,slug' . ($id ? ',' . $id : ''),
            'manufacturer' => 'nullable|string|max:64',
            'release_year' => 'nullable|year',
            'generation' => 'nullable|int|between:1,20',
            'description' => 'nullable|string|max:5000',
            'accent_color' => 'required|hex',
            'supported_formats' => 'nullable|string|max:255',
            'compatibility_notes' => 'nullable|string|max:5000',
            'install_notes' => 'nullable|string|max:5000',
            'sort_order' => 'nullable|int',
            'is_active' => 'bool',
        ];
    }

    private static function platformLabels(): array
    {
        return ['name' => 'اسم الجهاز', 'short_name' => 'الاسم المختصر', 'slug' => 'المعرف', 'accent_color' => 'اللون', 'release_year' => 'سنة الإصدار'];
    }

    public static function savePlatform(?int $id, array $input, bool $partial = false): int
    {
        $data = Validator::validate($input, self::platformRules($id), self::platformLabels(), $partial);
        $data['updated_at'] = now();
        if ($id) {
            if (!DB::value('SELECT 1 FROM platforms WHERE id = ?', [$id])) {
                throw new HttpException(404, 'الجهاز غير موجود');
            }
            DB::update('platforms', $data, 'id = ?', [$id]);
            ActivityLog::log('platform.updated', 'platform', $id, 'تعديل جهاز: ' . ($data['name'] ?? $id));
        } else {
            $data['manufacturer'] ??= 'Sony Interactive Entertainment';
            $data['sort_order'] ??= (int) DB::value('SELECT COALESCE(MAX(sort_order),0)+1 FROM platforms');
            $data['created_at'] = now();
            $id = DB::insert('platforms', $data);
            ActivityLog::log('platform.created', 'platform', $id, 'إضافة جهاز: ' . $data['name']);
        }
        Cache::bump('games');
        return $id;
    }

    public static function setPlatformImage(int $id, string $kind, string $srcPath): array
    {
        $p = DB::one('SELECT * FROM platforms WHERE id = ?', [$id]) ?? throw new HttpException(404, 'الجهاز غير موجود');
        $purpose = $kind === 'logo' ? 'platform_logo' : 'platform_image';
        $stored = MediaService::storeImage($srcPath, $purpose);
        $col = $kind === 'logo' ? 'logo_path' : 'image_path';
        MediaService::deleteImage($p[$col]);
        DB::update('platforms', [$col => $stored['path'], 'updated_at' => now()], 'id = ?', [$id]);
        Cache::bump('games');
        ActivityLog::log('platform.updated', 'platform', $id, 'تحديث صورة الجهاز ' . $p['short_name']);
        return MediaService::urls($stored['path'], $purpose) ?? [];
    }

    // ------------------------------------------------------------------ categories

    public static function categories(): array
    {
        return Cache::remember(Cache::key('games', 'categories'), 300, static fn () => DB::all(
            'SELECT c.*, (SELECT COUNT(*) FROM game_categories gc JOIN games g ON g.id = gc.game_id AND g.is_published = 1
                            WHERE gc.category_id = c.id) AS games_count
               FROM categories c ORDER BY c.sort_order, c.id'
        ));
    }

    public static function category(string|int $idOrSlug): ?array
    {
        foreach (self::categories() as $c) {
            if ((is_int($idOrSlug) || ctype_digit((string) $idOrSlug)) ? (int) $c['id'] === (int) $idOrSlug : $c['slug'] === $idOrSlug) {
                return $c;
            }
        }
        return null;
    }

    /** Resolves ids, slugs, Arabic or English names into category ids. */
    public static function resolveCategoryIds(array $values): array
    {
        $ids = [];
        $all = self::categories();
        foreach ($values as $v) {
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            foreach ($all as $c) {
                if ((ctype_digit($v) && (int) $v === (int) $c['id']) || strcasecmp($v, $c['slug']) === 0
                    || $v === $c['name'] || strcasecmp($v, (string) $c['name_en']) === 0) {
                    $ids[] = (int) $c['id'];
                    break;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    public static function saveCategory(?int $id, array $input, bool $partial = false): int
    {
        $data = Validator::validate($input, [
            'name' => 'required|string|max:64',
            'name_en' => 'nullable|string|max:64',
            'slug' => 'required|slug|max:64|unique:categories,slug' . ($id ? ',' . $id : ''),
            'icon' => 'nullable|string|max:32|regex:/^[a-z0-9\-]+$/',
            'color' => 'required|hex',
            'sort_order' => 'nullable|int',
        ], ['name' => 'اسم التصنيف', 'slug' => 'المعرف', 'color' => 'اللون'], $partial);
        $data['updated_at'] = now();
        if ($id) {
            if (!DB::value('SELECT 1 FROM categories WHERE id = ?', [$id])) {
                throw new HttpException(404, 'التصنيف غير موجود');
            }
            DB::update('categories', $data, 'id = ?', [$id]);
        } else {
            $data['sort_order'] ??= (int) DB::value('SELECT COALESCE(MAX(sort_order),0)+1 FROM categories');
            $data['created_at'] = now();
            $id = DB::insert('categories', $data);
        }
        Cache::bump('games');
        ActivityLog::log('category.saved', 'category', $id, 'حفظ تصنيف: ' . ($data['name'] ?? $id));
        return $id;
    }

    public static function deleteCategory(int $id): void
    {
        $c = DB::one('SELECT * FROM categories WHERE id = ?', [$id]) ?? throw new HttpException(404, 'التصنيف غير موجود');
        DB::delete('categories', 'id = ?', [$id]);
        Cache::bump('games');
        ActivityLog::log('category.deleted', 'category', $id, 'حذف تصنيف: ' . $c['name']);
    }
}
