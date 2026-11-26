<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\DB;

/**
 * Loads the fictional demo catalogue from database/demo (original procedural art, no real games).
 * Small demo files are generated on the fly so the download manager can be tested immediately.
 */
final class DemoSeeder
{
    private const COMPAT = [
        'ps5' => [['PS5', 'perfect', 'الإصدار الأصلي للجهاز']],
        'ps4' => [['PS5 (توافق رجعي)', 'perfect', 'تعمل بمعدل إطارات أعلى'], ['PS4 Pro', 'perfect', 'دعم 4K محسّن']],
        'ps3' => [['PS3 Slim', 'perfect', null], ['RPCS3', 'playable', 'يتطلب البرنامج الثابت الرسمي ونسخة تمتلكها']],
        'ps2' => [['PS2 Slim', 'perfect', null], ['PS3 (طرازات CECHA/CECHB)', 'playable', 'توافق عتادي'], ['PCSX2', 'playable', 'يتطلب BIOS من جهازك']],
        'ps1' => [['PS2', 'perfect', 'توافق رجعي كامل'], ['DuckStation', 'perfect', 'يتطلب BIOS من جهازك']],
    ];

    public static function run(?int $userId = null, ?callable $log = null): array
    {
        $dir = BASE_PATH . '/database/demo';
        $data = json_decode((string) file_get_contents($dir . '/games.json'), true)['games'] ?? [];
        $log ??= static fn (string $m) => null;
        UpdateService::$suppressAuto = true;
        $count = 0;
        $files = 0;
        $tmpDir = STORAGE_PATH . '/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        mt_srand(2026);
        try {
            foreach ($data as $g) {
                $plat = CatalogService::platform($g['platform']);
                if (!$plat) {
                    continue;
                }
                $saved = GameService::save(null, [
                    'platform_id' => (int) $plat['id'], 'title' => $g['title'], 'description' => $g['desc'], 'release_year' => $g['year'],
                    'developer' => $g['developer'], 'publisher' => $g['publisher'], 'age_rating' => $g['age'], 'size' => $g['size'],
                    'languages' => $g['langs'], 'region' => $g['region'], 'version' => $g['version'], 'edition' => $g['edition'],
                    'rating' => $g['rating'], 'product_code' => $g['code'], 'categories' => $g['categories'],
                    'is_published' => $g['published'] ?? true, 'is_featured' => $g['featured'] ?? false, 'allow_duplicate' => true,
                    'compatibility_notes' => $plat['slug'] === 'ps4' ? 'تعمل على PS5 عبر التوافق الرجعي الرسمي.' : null,
                ], false, false);
                $id = (int) $saved['id'];
                GameService::setImage($id, 'cover', $dir . '/' . $g['cover']);
                GameService::setImage($id, 'banner', $dir . '/' . $g['screens'][0]);
                foreach ($g['screens'] as $k => $shot) {
                    GameService::addScreenshot($id, $dir . '/' . $shot, 'لقطة توضيحية ' . ($k + 1));
                }
                $trailer = $dir . '/trailers/' . $g['id'] . '.mp4';
                if (is_file($trailer)) {
                    $tmp = $tmpDir . '/trailer-' . bin2hex(random_bytes(4)) . '.mp4';
                    copy($trailer, $tmp);
                    GameService::setTrailerFile($id, $tmp, 'trailer.mp4');
                }
                // versions
                GameService::saveVersion($id, null, ['version' => '1.00', 'region' => $g['region'], 'release_date' => $g['year'] . '-03-15',
                    'changelog' => 'الإصدار الأول.', 'is_latest' => $g['version'] === '1.00' || $g['version'] === '1.0', 'notify' => false]);
                if (!in_array($g['version'], ['1.00', '1.0'], true)) {
                    GameService::saveVersion($id, null, ['version' => $g['version'], 'region' => $g['region'], 'release_date' => min((int) date('Y'), $g['year'] + 1) . '-01-20',
                        'size' => '1.2 GB', 'changelog' => "تحسين الأداء والاستقرار.\nإصلاح مشاكل حفظ التقدم.\nإضافة دعم لغات جديدة للواجهة.", 'is_latest' => true, 'notify' => false]);
                }
                foreach (self::COMPAT[$plat['slug']] ?? [] as [$target, $status, $notes]) {
                    GameService::saveCompat($id, ['target' => $target, 'status' => $status, 'notes' => $notes, 'tested_at' => '2026-10-01']);
                }
                GameService::saveLink($id, null, ['title' => 'صفحة المتجر الرسمي (رابط تجريبي)', 'url' => 'https://example.com/store/' . $saved['id'],
                    'link_type' => 'official_store', 'region' => $g['region'], 'is_active' => true]);

                if (in_array($g['id'], [1, 3, 8, 10, 19, 26], true)) {
                    $txt = $tmpDir . '/manual-' . $id . '.txt';
                    file_put_contents($txt, "ULTIMATE PS LIBRARY — ملف تجريبي\n\nاللعبة: {$g['title']}\nهذا ملف نصي صغير لاختبار نظام التنزيل والتحقق من SHA-256.\n"
                        . str_repeat("سطر تجريبي لملء الملف بالبيانات.\n", 120));
                    FileService::importLocal($id, $txt, 'quick-start-guide.txt', ['file_type' => 'other', 'label' => 'دليل البدء السريع (ملف تجريبي)', 'access_level' => $g['id'] === 1 ? 'public' : 'registered'], $userId, true);
                    $files++;
                }
                if ($g['id'] === 3) {
                    $save = $tmpDir . '/save-' . $id . '.json';
                    file_put_contents($save, json_encode(['game' => $g['title'], 'slot' => 1, 'chapter' => 7, 'note' => 'demo save file'], JSON_PRETTY_PRINT));
                    FileService::importLocal($id, $save, 'save-slot-1.json', ['file_type' => 'save', 'label' => 'ملف حفظ تجريبي — الفصل السابع', 'access_level' => 'verified'], $userId, true);
                    $files++;
                }
                if ($g['id'] === 10) {
                    $bin = $tmpDir . '/pack-' . $id . '.bin';
                    $fp = fopen($bin, 'wb');
                    for ($i = 0; $i < 24; $i++) {
                        fwrite($fp, random_bytes(1024 * 1024));
                    }
                    fclose($fp);
                    FileService::importLocal($id, $bin, 'demo-data-pack.bin', ['file_type' => 'data', 'label' => 'حزمة بيانات تجريبية 24MB (لاختبار مدير التنزيل)', 'access_level' => 'registered', 'version_label' => $g['version']], $userId, true);
                    $files++;
                }
                // spread "added" dates and popularity so rails and filters look realistic
                $daysAgo = $g['id'] <= 6 ? mt_rand(0, 12) : mt_rand(10, 160);
                $when = gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400 - mt_rand(0, 86000));
                DB::update('games', [
                    'created_at' => $when, 'published_at' => ($g['published'] ?? true) ? $when : null,
                    'download_count' => (int) round($g['rating'] * mt_rand(40, 140)), 'view_count' => (int) round($g['rating'] * mt_rand(150, 600)),
                ], 'id = ?', [$id]);
                $count++;
                $log('  + ' . $g['title']);
            }
        } finally {
            UpdateService::$suppressAuto = false;
        }
        foreach (array_slice(array_reverse($data), 0, 8) as $g) {
            $gid = DB::value('SELECT id FROM games WHERE product_code = ? AND is_published = 1', [$g['code']]);
            if ($gid) {
                UpdateService::save(null, ['type' => 'game_added', 'game_id' => (int) $gid, 'title' => 'أضيفت إلى المكتبة: ' . $g['title'], 'is_published' => true]);
            }
        }
        $moon = DB::value("SELECT id FROM games WHERE product_code = 'DEMO-40004'");
        if ($moon) {
            UpdateService::save(null, ['type' => 'patch', 'game_id' => (int) $moon, 'title' => 'Moonwell Chronicles: الإصدار 1.30 متاح', 'body' => "دعم اللغة العربية الكامل.\nتحسينات في سرعة التحميل.", 'is_published' => true]);
        }
        Cache::bump('games');
        return ['games' => $count, 'files' => $files];
    }
}
