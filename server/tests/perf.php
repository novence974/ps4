<?php
declare(strict_types=1);
/**
 * ULTIMATE PS LIBRARY — load / performance test (run on the server machine).
 *
 *   php tests/perf.php --base=http://127.0.0.1:8080 [--games=5000] [--requests=400] [--concurrency=16] [--keep] [--report=docs/perf-report.json]
 *
 * 1) seeds N synthetic games (product codes PERF-…) straight into the database,
 * 2) fires concurrent HTTP requests (curl_multi) at the most used pages and API endpoints,
 * 3) prints p50 / p95 / p99 latency, requests per second and error counts,
 * 4) removes the synthetic games (unless --keep).
 */
require dirname(__DIR__) . '/app/bootstrap.php';
App\Core\Auth::actAsSystem();

use App\Core\Cache;
use App\Core\DB;
use App\Services\Settings;

$opt = ['base' => 'http://127.0.0.1:8080', 'games' => '5000', 'requests' => '400', 'concurrency' => '16'];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? '1';
    }
}
$base = rtrim($opt['base'], '/');
$nGames = max(0, (int) $opt['games']);
$nReq = max(10, (int) $opt['requests']);
$conc = max(1, (int) $opt['concurrency']);

$adj = ['Silent', 'Crimson', 'Neon', 'Iron', 'Lost', 'Hidden', 'Eternal', 'Shadow', 'Golden', 'Frozen', 'Electric', 'Savage', 'Hollow', 'Radiant', 'Broken', 'Wild', 'Ancient', 'Cyber', 'Lunar', 'Solar'];
$noun = ['Kingdom', 'Horizon', 'Legends', 'Drift', 'Odyssey', 'Protocol', 'Frontier', 'Echo', 'Citadel', 'Rift', 'Arena', 'Saga', 'Outpost', 'Dynasty', 'Circuit', 'Harbor', 'Tempest', 'Vanguard', 'Empire', 'Signal'];

// ------------------------------------------------------------------ seed
$platforms = DB::column('SELECT id FROM platforms');
$categories = DB::column('SELECT id FROM categories');
$existing = (int) DB::value("SELECT COUNT(*) FROM games WHERE product_code LIKE 'PERF-%'");
$t0 = microtime(true);
if ($existing < $nGames) {
    echo "Seeding " . ($nGames - $existing) . " synthetic games… ";
    $regions = ['EUR', 'USA', 'JPN', 'ASIA', 'MENA'];
    $langs = ['ar|en', 'en', 'en|fr|de', 'ja|en', 'ar|en|fr'];
    for ($start = $existing; $start < $nGames; $start += 500) {
        $rows = [];
        $params = [];
        $catRows = [];
        for ($i = $start; $i < min($nGames, $start + 500); $i++) {
            $title = $adj[$i % 20] . ' ' . $noun[intdiv($i, 20) % 20] . ' ' . (intdiv($i, 400) + 1);
            $created = gmdate('Y-m-d H:i:s', time() - random_int(0, 730) * 86400);
            $rows[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
            array_push($params, $platforms[$i % count($platforms)], $title, strtolower(preg_replace('/[^a-z0-9]+/i', '', $title)),
                'perf-' . $i . '-' . strtolower(str_replace(' ', '-', $title)), 'PERF-' . $i, 'Synthetic game used by the load test.',
                random_int(1995, 2026), $noun[$i % 20] . ' Studio', $adj[$i % 17] . ' Interactive', random_int(1, 120) * 1024 ** 3,
                $langs[$i % 5], $regions[$i % 5], round(random_int(40, 99) / 10, 1), $i % 3 ? 'available' : 'unavailable', 1,
                $created, random_int(0, 50000), $created, $created);
        }
        DB::run('INSERT INTO games (platform_id,title,title_normalized,slug,product_code,description,release_year,developer,publisher,size_bytes,'
            . 'languages,region,rating,files_status,is_published,published_at,download_count,created_at,updated_at) VALUES ' . implode(',', $rows), $params);
        $first = (int) DB::value('SELECT MIN(id) FROM games WHERE product_code = ?', ['PERF-' . $start]);
        $last = (int) DB::value('SELECT MAX(id) FROM games WHERE product_code LIKE ?', ['PERF-%']);
        for ($id = $first; $id <= $last; $id++) {
            foreach ((array) array_rand(array_flip($categories), random_int(1, 3)) as $c) {
                $catRows[] = "({$id}," . (int) $c . ')';
            }
        }
        DB::run('INSERT IGNORE INTO game_categories (game_id, category_id) VALUES ' . implode(',', $catRows));
    }
    Cache::bump('games');
    printf("done in %.1f s\n", microtime(true) - $t0);
}
$total = (int) DB::value('SELECT COUNT(*) FROM games WHERE is_published = 1');
$ids = DB::column("SELECT id FROM games WHERE is_published = 1 ORDER BY RAND() LIMIT 200");

// ------------------------------------------------------------------ load
$savedLimit = Settings::get('api.rate_limit_per_minute');
Settings::set('api.rate_limit_per_minute', 1000000); // keep the limiter in the request path, but never trip it here

function load(string $base, callable $urlFor, int $n, int $conc, array $headers = []): array
{
    $mh = curl_multi_init();
    $times = [];
    $errors = 0;
    $bytes = 0;
    $next = 0;
    $active = [];
    $add = static function () use (&$next, $n, $mh, $urlFor, $base, $headers, &$active): void {
        if ($next >= $n) {
            return;
        }
        $ch = curl_init($base . $urlFor($next));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => $headers]);
        curl_multi_add_handle($mh, $ch);
        $active[(int) $ch] = $ch;
        $next++;
    };
    for ($i = 0; $i < $conc; $i++) {
        $add();
    }
    $t = microtime(true);
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.05);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $times[] = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
            $bytes += (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
            if ($info['result'] !== CURLE_OK || $code >= 400) {
                $errors++;
            }
            curl_multi_remove_handle($mh, $ch);
            unset($active[(int) $ch]);
            $add();
        }
    } while ($running || $active);
    $wall = microtime(true) - $t;
    curl_multi_close($mh);
    sort($times);
    $pct = static fn (float $p) => $times[min(count($times) - 1, (int) floor($p * count($times)))] ?? 0;
    return ['n' => $n, 'p50' => round($pct(0.5), 1), 'p95' => round($pct(0.95), 1), 'p99' => round($pct(0.99), 1),
        'max' => round(end($times) ?: 0, 1), 'rps' => round($n / $wall, 1), 'errors' => $errors, 'kb' => round($bytes / max(1, $n) / 1024, 1)];
}

$words = array_merge($adj, $noun);
$scenarios = [
    'API: games list (random page)' => static fn (int $i) => '/api/v1/games?per_page=30&page=' . (($i % 50) + 1),
    'API: filter platform+category, sort rating' => static fn (int $i) => '/api/v1/games?platform=ps' . (($i % 5) + 1) . '&category=action&sort=rating&per_page=30',
    'API: full-text search (unique queries)' => static fn (int $i) => '/api/v1/search?q=' . urlencode($words[$i % 40] . ' ' . $words[($i * 7) % 40]) . '&page=' . (intdiv($i, 40) + 1),
    'API: instant search suggestions' => static fn (int $i) => '/api/v1/search?suggest=1&q=' . urlencode(substr($words[$i % 40], 0, 3 + $i % 3)),
    'API: game details (random game)' => static fn (int $i) => '/api/v1/games/' . $ids[$i % count($ids)],
    'API: platforms' => static fn (int $i) => '/api/v1/platforms',
    'WEB: home page' => static fn (int $i) => '/',
    'WEB: browse + search page' => static fn (int $i) => '/games?sort=rating&q=' . urlencode($words[$i % 40]),
    'WEB: game page' => static fn (int $i) => '/game/' . $ids[$i % count($ids)],
];

printf("\n%d published games · %d requests per scenario · concurrency %d · %s\n\n", $total, $nReq, $conc, $base);
printf("%-44s %8s %8s %8s %8s %8s %6s %8s\n", 'Scenario', 'p50 ms', 'p95 ms', 'p99 ms', 'max ms', 'req/s', 'errors', 'KB/req');
echo str_repeat('-', 104) . PHP_EOL;
$results = [];
try {
    Cache::flush(); // start cold: the first requests of each scenario fill the cache
    foreach ($scenarios as $name => $fn) {
        $r = load($base, $fn, $nReq, $conc, ['Accept: ' . (str_starts_with($name, 'WEB') ? 'text/html' : 'application/json')]);
        $results[$name] = $r;
        printf("%-44s %8.1f %8.1f %8.1f %8.1f %8.1f %6d %8.1f\n", $name, $r['p50'], $r['p95'], $r['p99'], $r['max'], $r['rps'], $r['errors'], $r['kb']);
    }
} finally {
    Settings::set('api.rate_limit_per_minute', $savedLimit);
}

// query plan check for the hottest list query (must use an index, never a full scan + filesort on games)
$plan = DB::all("EXPLAIN SELECT g.id FROM games g WHERE g.is_published = 1 ORDER BY g.created_at DESC LIMIT 30");
echo PHP_EOL . 'EXPLAIN games list: key=' . ($plan[0]['key'] ?? '-') . ', rows=' . ($plan[0]['rows'] ?? '-') . ', extra=' . ($plan[0]['Extra'] ?? '') . PHP_EOL;

if (!isset($opt['keep'])) {
    echo 'Removing synthetic games… ';
    DB::run("DELETE FROM games WHERE product_code LIKE 'PERF-%'");
    Cache::bump('games');
    Cache::flush();
    echo "done\n";
}
if (!empty($opt['report'])) {
    file_put_contents($opt['report'], json_encode([
        'date' => gmdate('c'), 'base' => $base, 'published_games' => $total, 'requests_per_scenario' => $nReq, 'concurrency' => $conc,
        'php' => PHP_VERSION, 'db' => DB::value('SELECT VERSION()'), 'cpu_cores' => (int) trim((string) @shell_exec('nproc')),
        'results' => $results, 'explain_list' => $plan[0] ?? null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
$failed = array_sum(array_column($results, 'errors'));
exit($failed ? 1 : 0);
