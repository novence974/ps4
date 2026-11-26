<?php
declare(strict_types=1);
/**
 * ULTIMATE PS LIBRARY — API, security and upload tests (run on the server machine).
 *
 *   php tests/run.php --base=http://127.0.0.1:8080 --admin=admin --admin-pass='...' [--filter=Upload] [--report=docs/test-report.json]
 *
 * The suite creates its own users and games (prefixed "zz-test") and removes them at the end.
 */
require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/app/bootstrap.php';
App\Core\Auth::actAsSystem();

use App\Core\Cache;
use App\Core\DB;
use App\Services\Queue;
use App\Services\Settings;

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) {
        $opt[$m[1]] = $m[2];
    }
}
$base = $opt['base'] ?? 'http://127.0.0.1:8080';
$http = new Http($base);
$S = new Suite();
// All test traffic comes from 127.0.0.1, so per-IP throttles are reset before each test.
// Dedicated tests below prove that the throttles do trigger.
$S->beforeEach = static fn () => Cache::flush();
$run = bin2hex(random_bytes(3));
$ctx = (object) ['run' => $run, 'users' => [], 'games' => [], 'tokens' => []];

// ------------------------------------------------------------------ fixtures
Cache::flush();
$savedSettings = [];
foreach (['api.rate_limit_per_minute' => 5000, 'auth.email_verification' => true, 'uploads.sync_assemble_limit_mb' => 2048, 'uploads.max_file_gb' => 200, 'auth.max_attempts' => 5] as $k => $v) {
    $savedSettings[$k] = Settings::get($k);
    Settings::set($k, $v);
}

function login_api(Http $http, string $login, string $pass, string $device = 'tests'): string
{
    $d = expect_ok($http->post('/api/v1/auth/login', ['json' => ['login' => $login, 'password' => $pass, 'device_name' => $device]]), 'login ' . $login);
    return $d['token'];
}

function register(Http $http, object $ctx, string $suffix, bool $verified = true): array
{
    $u = ['username' => "zz-test-{$ctx->run}-{$suffix}", 'email' => "zz-test-{$ctx->run}-{$suffix}@example.com", 'password' => 'Tester-pass-123', 'password_confirmation' => 'Tester-pass-123', 'device_name' => 'tests'];
    $d = expect_ok($http->post('/api/v1/auth/register', ['json' => $u]), 'register', 201);
    $ctx->users[] = (int) $d['user']['id'];
    if ($verified) {
        DB::update('users', ['email_verified_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [(int) $d['user']['id']]);
    }
    return ['id' => (int) $d['user']['id'], 'token' => $d['token'], 'username' => $u['username'], 'email' => $u['email'], 'password' => $u['password']];
}

function latest_mail_token(string $kind, string $to): ?string
{
    // newest message of this kind addressed to $to (several mails can share the same second)
    $files = glob(STORAGE_PATH . '/mail/*.eml') ?: [];
    rsort($files);
    foreach ($files as $f) {
        $raw = (string) file_get_contents($f);
        if (!preg_match('/^To: .*<' . preg_quote($to, '/') . '>/mi', $raw)) {
            continue;
        }
        if (preg_match_all('/Content-Transfer-Encoding: base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $raw, $m)) {
            foreach ($m[1] as $b64) {
                $txt = base64_decode(preg_replace('/\s+/', '', $b64));
                if (preg_match('#' . preg_quote($kind, '#') . '\?token=([a-f0-9]{64})#', (string) $txt, $t)) {
                    return $t[1];
                }
            }
        }
    }
    return null;
}

// ------------------------------------------------------------------ cleanup (also runs if a fixture fails)
$cleaned = false;
$cleanup = static function () use (&$cleaned, $ctx, &$savedSettings): void {
    if ($cleaned) {
        return;
    }
    $cleaned = true;
    echo PHP_EOL . 'Cleaning up test data… ';
    App\Core\Auth::actAsSystem();
    foreach (array_unique($ctx->games) as $gid) {
        try {
            App\Services\GameService::delete($gid);
        } catch (Throwable) {
        }
    }
    DB::run("DELETE FROM updates WHERE title LIKE 'zz-test%' OR id > ?", [(int) ($ctx->maxUpdateId ?? PHP_INT_MAX)]);
    foreach (DB::all("SELECT id FROM users WHERE username LIKE 'zz-test-%' OR username LIKE 'zz-weak-%' OR username LIKE 'zz-mm-%'") as $u) {
        DB::delete('users', 'id = ?', [(int) $u['id']]);
    }
    foreach (DB::all("SELECT uuid FROM uploads WHERE original_name LIKE 'zz-%' OR (original_name = 'c.png' AND game_id IS NULL)") as $u) {
        App\Services\UploadService::removeDir(App\Services\UploadService::chunksRoot() . '/' . $u['uuid']);
    }
    DB::run("DELETE FROM uploads WHERE original_name LIKE 'zz-%' OR (original_name = 'c.png' AND game_id IS NULL)");
    DB::run("DELETE FROM categories WHERE slug LIKE 'zz-test-%'");
    DB::run("DELETE FROM api_tokens WHERE name IN ('tests-admin')");
    foreach ($savedSettings as $k => $v) {
        Settings::set($k, $v);
    }
    Cache::flush();
    echo "done\n";
};
register_shutdown_function($cleanup);

$adminUser = $opt['admin'] ?? 'admin';
$adminPass = $opt['admin-pass'] ?? '';
$ctx->maxUpdateId = (int) DB::value('SELECT COALESCE(MAX(id), 0) FROM updates');
$ctx->admin = login_api($http, $adminUser, $adminPass, 'tests-admin');
$ctx->adminId = (int) DB::value('SELECT id FROM users WHERE username = ?', [$adminUser]);
$ctx->A = register($http, $ctx, 'a');
$ctx->B = register($http, $ctx, 'b');
$ctx->M = register($http, $ctx, 'mod');
expect_ok($http->put('/api/v1/admin/users/' . $ctx->M['id'], ['token' => $ctx->admin, 'json' => ['role_id' => 3]]), 'make moderator');
$ps5 = (int) DB::value("SELECT id FROM platforms WHERE slug = 'ps5'");
$ps4 = (int) DB::value("SELECT id FROM platforms WHERE slug = 'ps4'");

function create_game(Http $http, object $ctx, array $extra = []): array
{
    $d = expect_ok($http->post('/api/v1/admin/games', ['token' => $ctx->admin, 'json' => $extra + [
        'platform_id' => (int) DB::value("SELECT id FROM platforms WHERE slug = 'ps5'"), 'title' => 'zz-test Game ' . $ctx->run . ' ' . bin2hex(random_bytes(2)),
        'is_published' => true, 'categories' => ['action'], 'languages' => ['ar', 'en'], 'release_year' => 2025, 'rating' => 8.0,
    ]]), 'create game', 201);
    $ctx->games[] = (int) $d['id'];
    return $d;
}

/** Lets any upload/download test run on its own (e.g. with --filter): creates the game, file and signed link when missing. */
function ensure_upload_fixture(Http $http, object $ctx): void
{
    if (empty($ctx->upGame)) {
        $ctx->upGame = create_game($http, $ctx, ['title' => 'zz-test Upload ' . $ctx->run])['id'];
    }
    if (empty($ctx->file)) {
        $data = random_bytes(300000);
        $sha = hash('sha256', $data);
        $init = expect_ok($http->post('/api/v1/admin/uploads/init', ['token' => $ctx->admin, 'json' => ['purpose' => 'game_file', 'game_id' => $ctx->upGame, 'filename' => 'zz-fixture.bin', 'size' => strlen($data), 'access_level' => 'registered']]), 'fixture init', 201);
        expect_ok($http->post('/api/v1/admin/uploads/chunk', ['token' => $ctx->admin, 'headers' => ['X-Upload-Id' => $init['upload_id'], 'X-Chunk-Index' => '0'], 'body' => $data]), 'fixture chunk');
        $done = expect_ok($http->post('/api/v1/admin/uploads/complete', ['token' => $ctx->admin, 'json' => ['upload_id' => $init['upload_id'], 'sha256' => $sha]]), 'fixture complete');
        $ctx->file = ['id' => (int) $done['result']['file_id'], 'sha' => $sha, 'data' => $data, 'size' => strlen($data)];
    }
    if (empty($ctx->link)) {
        $ctx->link = expect_ok($http->post("/api/v1/games/{$ctx->upGame}/files/{$ctx->file['id']}/link", ['token' => $ctx->A['token']]), 'fixture link')['url'];
    }
}

// =====================================================================  PUBLIC API
$S->add('واجهة API العامة', 'GET /status يعرض حالة الخادم وقاعدة البيانات', function () use ($http) {
    $d = expect_ok($http->get('/api/v1/status'));
    check($d['status'] === 'ok' && $d['database']['ok'] === true, 'status ok');
    check(!isset($d['php']), 'details hidden from guests');
});
$S->add('واجهة API العامة', 'GET /status يعرض التفاصيل للمدير', function () use ($http, $ctx) {
    $d = expect_ok($http->get('/api/v1/status', ['token' => $ctx->admin]));
    check(isset($d['php'], $d['queue'], $d['extensions']), 'detailed health for admins');
});
$S->add('واجهة API العامة', 'GET /platforms يعيد الأجهزة الخمسة مع الصور', function () use ($http) {
    $d = expect_ok($http->get('/api/v1/platforms'));
    check(count($d) === 5, 'five platforms');
    check($d[0]['slug'] === 'ps5' && !empty($d[0]['logo']['md_png']), 'ps5 first, png logo for apps');
});
$S->add('واجهة API العامة', 'GET /platforms/{slug}', function () use ($http) {
    $d = expect_ok($http->get('/api/v1/platforms/ps2'));
    check($d['short_name'] === 'PS2' && $d['supported_formats'] !== null, 'platform detail');
    expect_status($http->get('/api/v1/platforms/ps9'), 404);
});
$S->add('واجهة API العامة', 'GET /categories يعيد 12 تصنيفاً', function () use ($http) {
    $d = expect_ok($http->get('/api/v1/categories'));
    check(count($d) >= 12, 'categories');
});
$S->add('واجهة API العامة', 'GET /games مع الصفحات والبيانات الوصفية', function () use ($http) {
    $r = $http->get('/api/v1/games', ['query' => ['per_page' => 5, 'page' => 2]]);
    $d = expect_ok($r);
    $meta = $r['json']['meta'];
    check(count($d) === 5 && $meta['page'] === 2 && $meta['per_page'] === 5 && $meta['total'] >= 20, 'pagination meta');
    check(isset($d[0]['cover']['sm_jpg']), 'JPEG cover variant for the PS4 app');
});
$filterCases = [
    'platform=ps4' => static fn ($g) => $g['platform']['slug'] === 'ps4',
    'category=racing' => static fn ($g) => in_array('racing', array_column($g['categories'], 'slug'), true),
    'year=2004' => static fn ($g) => $g['release_year'] === 2004,
    'language=ar' => static fn ($g) => in_array('ar', $g['languages'], true),
    'region=MENA' => static fn ($g) => $g['region'] === 'MENA',
    'size_min=50' => static fn ($g) => $g['size_bytes'] >= 50 * 1024 ** 3,
    'size_max=1' => static fn ($g) => $g['size_bytes'] <= 1024 ** 3,
    'rating_min=9' => static fn ($g) => $g['rating'] >= 9,
    'company=Iron' => static fn ($g) => str_starts_with((string) $g['developer'], 'Iron') || str_starts_with((string) $g['publisher'], 'Iron'),
    'availability=available' => static fn ($g) => $g['files_status'] === 'available',
    'added_within=7' => static fn ($g) => strtotime($g['created_at']) >= time() - 7 * 86400,
];
foreach ($filterCases as $qs => $pred) {
    $S->add('البحث والتصفية', "فلتر {$qs}", function () use ($http, $qs, $pred) {
        parse_str($qs, $q);
        $r = $http->get('/api/v1/games', ['query' => $q + ['per_page' => 100]]);
        $d = expect_ok($r);
        check(count($d) > 0, "no results for {$qs}");
        foreach ($d as $g) {
            check($pred($g), "item {$g['title']} does not match {$qs}");
        }
    });
}
foreach (['rating' => 'rating', 'downloads' => 'downloads', 'year' => 'release_year'] as $sort => $field) {
    $S->add('البحث والتصفية', "ترتيب sort={$sort} تنازلي", function () use ($http, $sort, $field) {
        $d = expect_ok($http->get('/api/v1/games', ['query' => ['sort' => $sort, 'per_page' => 40]]));
        $vals = array_values(array_filter(array_column($d, $field), static fn ($v) => $v !== null));
        for ($i = 1; $i < count($vals); $i++) {
            check($vals[$i - 1] >= $vals[$i], "{$sort} not descending at {$i}");
        }
    });
}
$S->add('البحث والتصفية', 'ترتيب أبجدي sort=title', function () use ($http) {
    $d = expect_ok($http->get('/api/v1/games', ['query' => ['sort' => 'title', 'per_page' => 40]]));
    $t = array_map(static fn ($g) => mb_strtolower($g['title']), $d);
    $sorted = $t;
    sort($sorted, SORT_STRING);
    check($t[0] === $sorted[0], 'alphabetical start');
});
$S->add('البحث والتصفية', 'بحث نصي باسم اللعبة ورمز المنتج', function () use ($http) {
    $d = expect_ok($http->get('/api/v1/search', ['query' => ['q' => 'moonwell']]));
    check(in_array('Moonwell Chronicles', array_column($d, 'title'), true), 'title search');
    $d = expect_ok($http->get('/api/v1/search', ['query' => ['q' => 'DEMO-50003']]));
    check(($d[0]['title'] ?? '') === 'Echoes of Qamar', 'product code search');
    $d = expect_ok($http->get('/api/v1/search', ['query' => ['q' => 'qamar']]));
    check(count($d) >= 1, 'word search');
});
$S->add('البحث والتصفية', 'اقتراحات البحث الفوري suggest=1', function () use ($http) {
    $r = $http->get('/api/v1/search', ['query' => ['q' => 'ne', 'suggest' => 1]]);
    $d = expect_ok($r);
    check(count($d) > 0 && count($d) <= 8 && isset($d[0]['url'], $d[0]['cover']), 'suggestions');
    check(str_starts_with($d[0]['url'], 'http') && str_starts_with($d[0]['cover'], 'http'), 'absolute URLs for the apps');
});
$S->add('البحث والتصفية', 'بحث بعنوان عربي', function () use ($http, $ctx) {
    $g = create_game($http, $ctx, ['title' => 'لعبة الصقر الذهبي ' . $ctx->run]);
    $d = expect_ok($http->get('/api/v1/search', ['query' => ['q' => 'الصقر']]));
    check(in_array($g['id'], array_column($d, 'id'), true), 'arabic title found');
});
$S->add('واجهة API العامة', 'تفاصيل لعبة بالمعرف والرابط النصي + 404', function () use ($http) {
    $slug = DB::value("SELECT slug FROM games WHERE product_code = 'DEMO-50003'");
    $d = expect_ok($http->get('/api/v1/games/' . $slug));
    check($d['title'] === 'Echoes of Qamar' && count($d['screenshots']) === 3 && $d['trailer'] !== null, 'details');
    check(count($d['files']) >= 1 && isset($d['files'][0]['sha256']) && !isset($d['files'][0]['disk']), 'files without internal paths');
    check(isset($d['platform_info']['install_notes']), 'platform compatibility info');
    $byId = expect_ok($http->get('/api/v1/games/' . $d['id']));
    check($byId['slug'] === $slug, 'by id');
    $r = $http->get('/api/v1/games/does-not-exist-123');
    expect_status($r, 404);
    check($r['json']['error']['code'] === 'not_found', 'error code');
});
$S->add('واجهة API العامة', 'ألعاب مشابهة وقائمة التحديثات', function () use ($http) {
    $id = (int) DB::value("SELECT id FROM games WHERE product_code = 'DEMO-40004'");
    expect_ok($http->get("/api/v1/games/{$id}/related"));
    $d = expect_ok($http->get('/api/v1/updates'));
    check(count($d) > 0 && isset($d[0]['type_label']), 'updates feed');
});
$S->add('الأداء', 'ETag و 304 Not Modified للطلبات المتكررة', function () use ($http) {
    $r = $http->get('/api/v1/categories');
    $etag = header_value($r, 'etag');
    check($etag !== null, 'etag header');
    $r2 = $http->get('/api/v1/categories', ['headers' => ['If-None-Match' => $etag]]);
    expect_status($r2, 304);
    check($r2['body'] === '', 'empty 304 body');
});
$S->add('الأداء', 'ضغط gzip لاستجابات API', function () use ($http) {
    $r = $http->get('/api/v1/games', ['query' => ['per_page' => 30], 'headers' => ['Accept-Encoding' => 'gzip'], 'encoding' => 'gzip']);
    check(header_value($r, 'content-encoding') === 'gzip', 'gzip encoding');
});

// =====================================================================  AUTH
$S->add('الحسابات والمصادقة', 'التسجيل يرسل بريد تأكيد، والرابط يؤكد الحساب', function () use ($http, $ctx) {
    $u = register($http, $ctx, 'verify', false);
    $tok = latest_mail_token('verify-email', $u['email']);
    check($tok !== null, 'verification e-mail written');
    $r = $http->get('/verify-email?token=' . $tok, ['accept' => 'text/html']);
    expect_status($r, 302);
    check(DB::value('SELECT email_verified_at FROM users WHERE id = ?', [$u['id']]) !== null, 'verified in DB');
});
$S->add('الحسابات والمصادقة', 'رفض اسم مستخدم مكرر وكلمة مرور ضعيفة وتأكيد غير مطابق', function () use ($http, $ctx) {
    $r = $http->post('/api/v1/auth/register', ['json' => ['username' => $ctx->A['username'], 'email' => 'x' . $ctx->run . '@example.com', 'password' => 'Abcdefg-123', 'password_confirmation' => 'Abcdefg-123']]);
    expect_status($r, 422);
    check(isset($r['json']['error']['fields']['username']), 'username taken');
    $r = $http->post('/api/v1/auth/register', ['json' => ['username' => 'zz-weak-' . $ctx->run, 'email' => 'w' . $ctx->run . '@example.com', 'password' => 'onlyletters', 'password_confirmation' => 'onlyletters']]);
    expect_status($r, 422);
    $r = $http->post('/api/v1/auth/register', ['json' => ['username' => 'zz-mm-' . $ctx->run, 'email' => 'm' . $ctx->run . '@example.com', 'password' => 'Abcdefg-123', 'password_confirmation' => 'Different-123']]);
    expect_status($r, 422);
});
$S->add('الحسابات والمصادقة', 'تسجيل الدخول بالبريد ورفض كلمة مرور خاطئة', function () use ($http, $ctx) {
    login_api($http, $ctx->A['email'], $ctx->A['password']);
    $r = $http->post('/api/v1/auth/login', ['json' => ['login' => $ctx->A['username'], 'password' => 'wrong-pass-1']]);
    expect_status($r, 422);
    DB::update('users', ['failed_logins' => 0], 'id = ?', [$ctx->A['id']]);
});
$S->add('الحسابات والمصادقة', 'الملف الشخصي يعرض الدور والصلاحيات', function () use ($http, $ctx) {
    $d = expect_ok($http->get('/api/v1/user/profile', ['token' => $ctx->A['token']]));
    check($d['role']['slug'] === 'user' && $d['permissions'] === ['files.download'] && isset($d['stats']), 'profile');
});
$S->add('الحسابات والمصادقة', 'تغيير كلمة المرور يلغي جلسات الأجهزة الأخرى', function () use ($http, $ctx) {
    $u = register($http, $ctx, 'pw');
    $other = login_api($http, $u['username'], $u['password'], 'second-device');
    expect_ok($http->put('/api/v1/user/password', ['token' => $u['token'], 'json' => ['current_password' => $u['password'], 'password' => 'New-pass-456', 'password_confirmation' => 'New-pass-456']]));
    expect_status($http->get('/api/v1/user/profile', ['token' => $other]), 401, 'other device revoked');
    expect_ok($http->get('/api/v1/user/profile', ['token' => $u['token']]), 'current device kept');
});
$S->add('الحسابات والمصادقة', 'استعادة كلمة المرور عبر البريد', function () use ($http, $ctx) {
    $u = register($http, $ctx, 'reset');
    expect_ok($http->post('/api/v1/auth/forgot-password', ['json' => ['email' => $u['email']]]));
    $tok = latest_mail_token('reset-password', $u['email']);
    check($tok !== null, 'reset mail');
    expect_ok($http->post('/api/v1/auth/reset-password', ['json' => ['token' => $tok, 'password' => 'Reset-pass-789', 'password_confirmation' => 'Reset-pass-789']]));
    login_api($http, $u['username'], 'Reset-pass-789');
    expect_status($http->post('/api/v1/auth/reset-password', ['json' => ['token' => $tok, 'password' => 'Again-pass-789', 'password_confirmation' => 'Again-pass-789']]), 422, 'token single use');
});
$S->add('الحسابات والمصادقة', 'تسجيل الخروج يلغي الرمز', function () use ($http, $ctx) {
    $t = login_api($http, $ctx->B['username'], $ctx->B['password'], 'to-logout');
    expect_ok($http->post('/api/v1/auth/logout', ['token' => $t]));
    expect_status($http->get('/api/v1/user/profile', ['token' => $t]), 401);
});
$S->add('الحسابات والمصادقة', 'إدارة الأجهزة المتصلة (عرض وإلغاء)', function () use ($http, $ctx) {
    $t = login_api($http, $ctx->B['username'], $ctx->B['password'], 'tablet');
    $list = expect_ok($http->get('/api/v1/user/tokens', ['token' => $ctx->B['token']]));
    $tab = array_values(array_filter($list, static fn ($x) => $x['name'] === 'tablet'))[0] ?? null;
    check($tab !== null, 'device listed');
    expect_ok($http->delete('/api/v1/user/tokens/' . $tab['id'], ['token' => $ctx->B['token']]));
    expect_status($http->get('/api/v1/user/profile', ['token' => $t]), 401);
});
$S->add('الحسابات والمصادقة', 'قفل الحساب بعد محاولات فاشلة ثم فكه من الإدارة', function () use ($http, $ctx) {
    $u = register($http, $ctx, 'lock');
    for ($i = 0; $i < 5; $i++) {
        $http->post('/api/v1/auth/login', ['json' => ['login' => $u['username'], 'password' => 'bad-' . $i]]);
    }
    $r = $http->post('/api/v1/auth/login', ['json' => ['login' => $u['username'], 'password' => $u['password']]]);
    expect_status($r, 429, 'locked even with right password');
    check(($r['json']['error']['code'] ?? '') === 'account_locked', 'account_locked code');
    expect_ok($http->put('/api/v1/admin/users/' . $u['id'], ['token' => $ctx->admin, 'json' => ['unlock' => true]]));
    login_api($http, $u['username'], $u['password']);
});

// =====================================================================  FAVORITES / HISTORY / NOTIFICATIONS
$S->add('المفضلة والسجلات والإشعارات', 'إضافة وعرض وحذف المفضلة', function () use ($http, $ctx) {
    $gid = (int) DB::value("SELECT id FROM games WHERE product_code = 'DEMO-40004'");
    expect_ok($http->post('/api/v1/user/favorites', ['token' => $ctx->A['token'], 'json' => ['game_id' => $gid]]), 'add', 201);
    $list = expect_ok($http->get('/api/v1/user/favorites', ['token' => $ctx->A['token']]));
    check(in_array($gid, array_column($list, 'id'), true) && $list[0]['is_favorite'] === true, 'listed');
    $g = expect_ok($http->get('/api/v1/games/' . $gid, ['token' => $ctx->A['token']]));
    check($g['is_favorite'] === true, 'flag on details');
    $ctx->favGame = $gid;
});
$S->add('المفضلة والسجلات والإشعارات', 'نشر تحديث يرسل إشعاراً لمن أضاف اللعبة للمفضلة', function () use ($http, $ctx) {
    $before = expect_ok($http->get('/api/v1/user/profile', ['token' => $ctx->A['token']]))['unread_notifications'];
    expect_ok($http->post('/api/v1/admin/updates', ['token' => $ctx->admin, 'json' => ['type' => 'patch', 'game_id' => $ctx->favGame, 'title' => 'zz-test patch ' . $ctx->run, 'is_published' => true, 'notify_favorites' => true]]), 'update', 201);
    $r = $http->get('/api/v1/user/notifications', ['token' => $ctx->A['token']]);
    $d = expect_ok($r);
    check($r['json']['meta']['unread'] > $before && str_contains($d[0]['title'], 'zz-test patch'), 'notified');
    $mine = expect_ok($http->get('/api/v1/user/updates', ['token' => $ctx->A['token']]));
    check(str_contains($mine[0]['title'], 'zz-test patch'), 'update history for favourites');
    $after = expect_ok($http->post('/api/v1/user/notifications/read', ['token' => $ctx->A['token'], 'json' => []]));
    check($after['unread'] === 0, 'all read');
    expect_ok($http->delete('/api/v1/user/favorites/' . $ctx->favGame, ['token' => $ctx->A['token']]));
});
$S->add('المفضلة والسجلات والإشعارات', 'سجل المشاهدة يُحفظ عند فتح لعبة', function () use ($http, $ctx) {
    $gid = (int) DB::value("SELECT id FROM games WHERE product_code = 'DEMO-20001'");
    expect_ok($http->get('/api/v1/games/' . $gid, ['token' => $ctx->B['token']]));
    $h = expect_ok($http->get('/api/v1/user/history', ['token' => $ctx->B['token']]));
    check($h[0]['id'] === $gid && isset($h[0]['viewed_at']), 'history');
    expect_ok($http->delete('/api/v1/user/history', ['token' => $ctx->B['token']]));
    check(expect_ok($http->get('/api/v1/user/history', ['token' => $ctx->B['token']])) === [], 'cleared');
});

// =====================================================================  ADMIN: GAMES
$S->add('إدارة الألعاب', 'إضافة لعبة بكل الحقول', function () use ($http, $ctx, $ps5) {
    $g = create_game($http, $ctx, ['title' => 'zz-test Full ' . $ctx->run, 'size' => '10.5 GB', 'categories' => ['action', 'rpg'], 'languages' => 'ar|en|fr',
        'developer' => 'Test Studio', 'publisher' => 'Test Pub', 'age_rating' => 'PEGI 16', 'region' => 'EUR', 'version' => '1.02', 'product_code' => 'ZZT-' . $ctx->run,
        'description' => 'وصف تجريبي', 'trailer_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
    check($g['size_bytes'] === (int) round(10.5 * 1024 ** 3) && count($g['categories']) === 2 && $g['languages'] === ['ar', 'en', 'fr'], 'fields saved');
    check($g['trailer']['type'] === 'youtube' && str_contains($g['trailer']['embed'], 'youtube-nocookie.com'), 'privacy-friendly embed');
    $ctx->full = $g;
});
$S->add('إدارة الألعاب', 'اكتشاف التكرار (409) والسماح به صراحة', function () use ($http, $ctx, $ps5) {
    $r = $http->post('/api/v1/admin/games', ['token' => $ctx->admin, 'json' => ['platform_id' => $ps5, 'title' => 'ZZ-TEST full ' . $ctx->run . '!!', 'region' => 'EUR']]);
    expect_status($r, 409);
    check($r['json']['error']['code'] === 'duplicate_game' && $r['json']['error']['duplicates'][0]['id'] === $ctx->full['id'], 'duplicate listed');
    $chk = expect_ok($http->get('/api/v1/admin/games/check-duplicates', ['token' => $ctx->admin, 'query' => ['title' => 'zz test full ' . $ctx->run, 'platform_id' => $ps5]]));
    check(($chk[0]['match'] ?? '') === 'exact', 'live check');
    $d = expect_ok($http->post('/api/v1/admin/games', ['token' => $ctx->admin, 'json' => ['platform_id' => $ps5, 'title' => 'zz-test Full ' . $ctx->run, 'region' => 'USA', 'allow_duplicate' => true]]), 'allowed', 201);
    $ctx->games[] = (int) $d['id'];
    $groups = expect_ok($http->get('/api/v1/admin/duplicates', ['token' => $ctx->admin]));
    check(count(array_filter($groups, static fn ($g) => in_array($ctx->full['id'], array_column($g['games'], 'id'), true))) > 0, 'duplicates page');
});
$S->add('إدارة الألعاب', 'تعديل جزئي PUT لا يمس الحقول الأخرى', function () use ($http, $ctx) {
    $d = expect_ok($http->put('/api/v1/admin/games/' . $ctx->full['id'], ['token' => $ctx->admin, 'json' => ['rating' => 9.4]]));
    check($d['rating'] === 9.4 && $d['title'] === $ctx->full['title'] && count($d['categories']) === 2, 'partial update');
});
$S->add('إدارة الألعاب', 'الإخفاء يمنع ظهور اللعبة للزوار والنشر يعيدها', function () use ($http, $ctx) {
    $id = $ctx->full['id'];
    expect_ok($http->post("/api/v1/admin/games/{$id}/publish", ['token' => $ctx->admin, 'json' => ['published' => false]]));
    expect_status($http->get("/api/v1/games/{$id}"), 404);
    expect_ok($http->post("/api/v1/admin/games/{$id}/publish", ['token' => $ctx->admin, 'json' => ['published' => true]]));
    expect_ok($http->get("/api/v1/games/{$id}"));
});
$S->add('إدارة الألعاب', 'رفع الغلاف والخلفية والصور (WebP + JPEG)', function () use ($http, $ctx, $base) {
    $id = $ctx->full['id'];
    $png = make_png();
    $d = expect_ok($http->post("/api/v1/admin/games/{$id}/cover", ['token' => $ctx->admin, 'multipart' => ['file' => new CURLFile($png, 'image/png', 'cover.png')]]));
    check(isset($d['urls']['sm'], $d['urls']['md_jpg']), 'variants');
    $img = $http->get($d['urls']['md'], ['accept' => '*/*']);
    expect_status($img, 200);
    check(str_starts_with((string) header_value($img, 'content-type'), 'image/webp'), 'webp served');
    expect_ok($http->post("/api/v1/admin/games/{$id}/banner", ['token' => $ctx->admin, 'multipart' => ['file' => new CURLFile(make_png(1920, 1080), 'image/png', 'b.png')]]));
    $s = expect_ok($http->post("/api/v1/admin/games/{$id}/screenshots", ['token' => $ctx->admin, 'multipart' => ['file' => new CURLFile(make_png(1600, 900), 'image/png', 's.png'), 'caption' => 'لقطة']]), 'shot', 201);
    expect_ok($http->delete("/api/v1/admin/games/{$id}/screenshots/{$s['id']}", ['token' => $ctx->admin]));
});
$S->add('إدارة الألعاب', 'الإصدارات والروابط والتوافق', function () use ($http, $ctx) {
    $id = $ctx->full['id'];
    $v = expect_ok($http->post("/api/v1/admin/games/{$id}/versions", ['token' => $ctx->admin, 'json' => ['version' => '1.10', 'changelog' => 'إصلاحات', 'is_latest' => true]]), 'version', 201);
    $v2 = expect_ok($http->put("/api/v1/admin/games/{$id}/versions/{$v['id']}", ['token' => $ctx->admin, 'json' => ['version' => '1.11', 'is_latest' => true]]));
    check($v2['version'] === '1.11', 'version edited');
    check(expect_ok($http->get("/api/v1/admin/games/{$id}", ['token' => $ctx->admin]))['version'] === '1.11', 'latest synced to game');
    expect_status($http->post("/api/v1/admin/games/{$id}/links", ['token' => $ctx->admin, 'json' => ['title' => 'x', 'url' => 'javascript:alert(1)', 'link_type' => 'official_store']]), 422, 'javascript: url rejected');
    $l = expect_ok($http->post("/api/v1/admin/games/{$id}/links", ['token' => $ctx->admin, 'json' => ['title' => 'المتجر', 'url' => 'https://example.com/x', 'link_type' => 'official_store']]), 'link', 201);
    expect_ok($http->delete("/api/v1/admin/games/{$id}/links/{$l['id']}", ['token' => $ctx->admin]));
    $c1 = expect_ok($http->post("/api/v1/admin/games/{$id}/compat", ['token' => $ctx->admin, 'json' => ['target' => 'PS5', 'status' => 'playable']]));
    $c2 = expect_ok($http->post("/api/v1/admin/games/{$id}/compat", ['token' => $ctx->admin, 'json' => ['target' => 'PS5', 'status' => 'perfect']]));
    check($c1['id'] === $c2['id'], 'compat upsert');
    expect_ok($http->delete("/api/v1/admin/games/{$id}/versions/{$v['id']}", ['token' => $ctx->admin]));
});
$S->add('إدارة الألعاب', 'الاستيراد بالجملة CSV: معاينة ثم تنفيذ', function () use ($http, $ctx) {
    $csv = tempnam(sys_get_temp_dir(), 'csv') . '.csv';
    file_put_contents($csv, "\xEF\xBB\xBFtitle,platform,categories,release_year,size,rating,region\n"
        . "zz-test Import {$ctx->run},ps4,racing|action,2021,12 GB,7.5,EUR\n"
        . "Moonwell Chronicles,ps4,rpg,2020,61.5 GB,9.3,EUR\n"
        . "Broken Row,ps9,action,2021,1 GB,5,EUR\n");
    $p = expect_ok($http->post('/api/v1/admin/import', ['token' => $ctx->admin, 'multipart' => ['file' => new CURLFile($csv, 'text/csv', 'games.csv'), 'mode' => 'preview']]));
    check($p['summary']['new'] === 1 && $p['summary']['duplicate'] === 1 && $p['summary']['invalid'] === 1, 'preview summary ' . json_encode($p['summary']));
    $c = expect_ok($http->post('/api/v1/admin/import', ['token' => $ctx->admin, 'json' => ['token' => $p['token'], 'mode' => 'commit', 'on_duplicate' => 'skip']]));
    check($c['created'] === 1 && $c['skipped'] === 2, 'committed');
    $id = (int) DB::value('SELECT id FROM games WHERE title = ?', ['zz-test Import ' . $ctx->run]);
    check($id > 0, 'imported row');
    $ctx->games[] = $id;
});
$S->add('إدارة الألعاب', 'إدارة الأجهزة والتصنيفات', function () use ($http, $ctx) {
    $pid = (int) DB::value("SELECT id FROM platforms WHERE slug = 'ps3'");
    $old = DB::value('SELECT description FROM platforms WHERE id = ?', [$pid]);
    $d = expect_ok($http->put('/api/v1/admin/platforms/' . $pid, ['token' => $ctx->admin, 'json' => ['description' => 'zz-test desc']]));
    check($d['description'] === 'zz-test desc' && $d['short_name'] === 'PS3', 'partial platform update');
    expect_ok($http->put('/api/v1/admin/platforms/' . $pid, ['token' => $ctx->admin, 'json' => ['description' => $old]]));
    $c = expect_ok($http->post('/api/v1/admin/categories', ['token' => $ctx->admin, 'json' => ['name' => 'تجربة', 'slug' => 'zz-test-' . $ctx->run, 'color' => '#123456']]), 'cat', 201);
    expect_ok($http->delete('/api/v1/admin/categories/' . $c['id'], ['token' => $ctx->admin]));
});
$S->add('إدارة الألعاب', 'إحصاءات لوحة التحكم وسجل النشاط', function () use ($http, $ctx) {
    $s = expect_ok($http->get('/api/v1/admin/stats', ['token' => $ctx->admin]));
    check($s['games_total'] >= 30 && count($s['by_platform']) === 5 && count($s['downloads_daily']) === 30, 'stats');
    $l = expect_ok($http->get('/api/v1/admin/logs', ['token' => $ctx->admin]));
    check(count($l) > 0 && isset($l[0]['label']), 'activity log');
    expect_ok($http->get('/api/v1/admin/storage', ['token' => $ctx->admin]));
});

// =====================================================================  UPLOADS & DOWNLOADS
$S->add('الرفع المجزأ والاستكمال', 'رفع ملف على أجزاء مع انقطاع واستكمال وتحقق SHA-256', function () use ($http, $ctx) {
    $g = create_game($http, $ctx, ['title' => 'zz-test Upload ' . $ctx->run]);
    $ctx->upGame = $g['id'];
    $chunk = expect_ok($http->get('/api/v1/status'))['chunk_size'];
    $size = $chunk * 2 + 777;
    $data = random_bytes($size);
    $sha = hash('sha256', $data);
    $meta = ['purpose' => 'game_file', 'game_id' => $g['id'], 'filename' => 'zz-test-pack.bin', 'size' => $size, 'file_type' => 'data', 'access_level' => 'registered', 'sha256' => $sha];
    $init = expect_ok($http->post('/api/v1/admin/uploads/init', ['token' => $ctx->admin, 'json' => $meta]), 'init', 201);
    check($init['total_chunks'] === 3 && $init['chunk_size'] === $chunk && $init['received'] === [], 'session');
    $id = $init['upload_id'];
    $part = static fn (int $i) => substr($data, $i * $chunk, $chunk);
    $send = static fn (int $i, ?string $hash = null, ?string $body = null) => $http->post('/api/v1/admin/uploads/chunk', ['token' => $ctx->admin,
        'headers' => ['X-Upload-Id' => $id, 'X-Chunk-Index' => (string) $i, 'X-Chunk-Sha256' => $hash ?? hash('sha256', $part($i))], 'body' => $body ?? $part($i)]);
    $r = expect_ok($send(0));
    check($r['received_chunks'] === 1, 'chunk 0');
    $r = expect_ok($send(0));
    check($r['received_chunks'] === 1, 'duplicate chunk is idempotent');
    $bad = $send(1, str_repeat('0', 64));
    expect_status($bad, 422, 'checksum');
    check($bad['json']['error']['code'] === 'chunk_checksum_mismatch', 'checksum code');
    $short = $send(1, null, substr($part(1), 0, 100));
    expect_status($short, 422, 'size');
    // client "loses" its state (disconnect / app restart) and initialises again
    $again = expect_ok($http->post('/api/v1/admin/uploads/init', ['token' => $ctx->admin, 'json' => $meta]), 'resume init', 200);
    check($again['resumed'] === true && $again['upload_id'] === $id && $again['received'] === [0], 'resumed from chunk 1');
    expect_status($http->post('/api/v1/admin/uploads/complete', ['token' => $ctx->admin, 'json' => ['upload_id' => $id]]), 409, 'incomplete');
    // chunk 1 as multipart, chunk 2 as base64 JSON (alternative client styles)
    $tmp = tempnam(sys_get_temp_dir(), 'ck');
    file_put_contents($tmp, $part(1));
    expect_ok($http->post('/api/v1/admin/uploads/chunk', ['token' => $ctx->admin, 'multipart' => ['upload_id' => $id, 'chunk_index' => '1', 'chunk_sha256' => hash('sha256', $part(1)), 'chunk' => new CURLFile($tmp, 'application/octet-stream', 'c1')]]), 'multipart chunk');
    expect_ok($http->post('/api/v1/admin/uploads/chunk', ['token' => $ctx->admin, 'json' => ['upload_id' => $id, 'chunk_index' => 2, 'data_base64' => base64_encode($part(2))]]), 'base64 chunk');
    $st = expect_ok($http->get('/api/v1/admin/uploads/' . $id, ['token' => $ctx->admin]));
    check($st['received'] === [0, 1, 2] && $st['received_bytes'] === $size, 'all chunks');
    $done = expect_ok($http->post('/api/v1/admin/uploads/complete', ['token' => $ctx->admin, 'json' => ['upload_id' => $id, 'sha256' => $sha]]));
    check($done['status'] === 'completed' && $done['sha256'] === $sha && !empty($done['result']['file_id']), 'completed with matching SHA-256');
    $row = DB::one('SELECT * FROM game_files WHERE id = ?', [$done['result']['file_id']]);
    check($row['sha256'] === $sha && (int) $row['size_bytes'] === $size, 'registered');
    check(hash_file('sha256', App\Services\FileService::absolutePath($row)) === $sha, 'bytes on disk identical');
    check(!is_dir(App\Services\UploadService::chunksRoot() . '/' . $id), 'chunks cleaned');
    $ctx->file = ['id' => (int) $row['id'], 'sha' => $sha, 'data' => $data, 'size' => $size];
});
$S->add('الرفع المجزأ والاستكمال', 'رفض SHA-256 نهائي غير مطابق', function () use ($http, $ctx) {
    ensure_upload_fixture($http, $ctx);
    $data = random_bytes(1000);
    $init = expect_ok($http->post('/api/v1/admin/uploads/init', ['token' => $ctx->admin, 'json' => ['purpose' => 'game_file', 'game_id' => $ctx->upGame, 'filename' => 'zz-bad.bin', 'size' => 1000]]), 'init', 201);
    expect_ok($http->post('/api/v1/admin/uploads/chunk', ['token' => $ctx->admin, 'headers' => ['X-Upload-Id' => $init['upload_id'], 'X-Chunk-Index' => '0'], 'body' => $data]));
    $r = $http->post('/api/v1/admin/uploads/complete', ['token' => $ctx->admin, 'json' => ['upload_id' => $init['upload_id'], 'sha256' => str_repeat('a', 64)]]);
    expect_status($r, 422);
    check(str_contains($r['json']['error']['message'], 'SHA-256'), 'message');
});
$S->add('الرفع المجزأ والاستكمال', 'التجميع في الخلفية عبر طابور المهام للملفات الكبيرة', function () use ($http, $ctx) {
    ensure_upload_fixture($http, $ctx);
    Settings::set('uploads.sync_assemble_limit_mb', 1);
    try {
        $data = random_bytes(1024 * 1024 + 10);
        $init = expect_ok($http->post('/api/v1/admin/uploads/init', ['token' => $ctx->admin, 'json' => ['purpose' => 'game_file', 'game_id' => $ctx->upGame, 'filename' => 'zz-big.bin', 'size' => strlen($data), 'file_type' => 'update']]), 'init', 201);
        expect_ok($http->post('/api/v1/admin/uploads/chunk', ['token' => $ctx->admin, 'headers' => ['X-Upload-Id' => $init['upload_id'], 'X-Chunk-Index' => '0'], 'body' => $data]));
        $r = $http->post('/api/v1/admin/uploads/complete', ['token' => $ctx->admin, 'json' => ['upload_id' => $init['upload_id'], 'sha256' => hash('sha256', $data)]]);
        $d = expect_ok($r, 'complete', 202);
        check($d['status'] === 'processing', 'queued');
        Queue::work(30, 20);
        $st = expect_ok($http->get('/api/v1/admin/uploads/' . $init['upload_id'], ['token' => $ctx->admin]));
        check($st['status'] === 'completed', 'worker assembled the file');
    } finally {
        Settings::set('uploads.sync_assemble_limit_mb', 2048);
    }
});
$S->add('الرفع المجزأ والاستكمال', 'رفع غلاف عبر الرفع المجزأ (purpose=cover)', function () use ($http, $ctx) {
    ensure_upload_fixture($http, $ctx);
    $png = (string) file_get_contents(make_png());
    $init = expect_ok($http->post('/api/v1/admin/uploads/init', ['token' => $ctx->admin, 'json' => ['purpose' => 'cover', 'game_id' => $ctx->upGame, 'filename' => 'c.png', 'size' => strlen($png)]]), 'init', 201);
    expect_ok($http->post('/api/v1/admin/uploads/chunk', ['token' => $ctx->admin, 'headers' => ['X-Upload-Id' => $init['upload_id'], 'X-Chunk-Index' => '0'], 'body' => $png]));
    $d = expect_ok($http->post('/api/v1/admin/uploads/complete', ['token' => $ctx->admin, 'json' => ['upload_id' => $init['upload_id']]]));
    check($d['status'] === 'completed' && isset($d['result']['urls']['md']), 'cover applied');
    check(DB::value('SELECT cover_path FROM games WHERE id = ?', [$ctx->upGame]) !== null, 'cover saved');
});
$S->add('الرفع المجزأ والاستكمال', 'إلغاء عملية رفع', function () use ($http, $ctx) {
    ensure_upload_fixture($http, $ctx);
    $init = expect_ok($http->post('/api/v1/admin/uploads/init', ['token' => $ctx->admin, 'json' => ['purpose' => 'game_file', 'game_id' => $ctx->upGame, 'filename' => 'zz-cancel.bin', 'size' => 5000]]), 'init', 201);
    expect_ok($http->delete('/api/v1/admin/uploads/' . $init['upload_id'], ['token' => $ctx->admin]));
    expect_status($http->post('/api/v1/admin/uploads/chunk', ['token' => $ctx->admin, 'headers' => ['X-Upload-Id' => $init['upload_id'], 'X-Chunk-Index' => '0'], 'body' => random_bytes(5000)]), 409);
});
$S->add('التنزيل المصرح به', 'رابط موقّع + تنزيل كامل مطابق للبصمة', function () use ($http, $ctx) {
    ensure_upload_fixture($http, $ctx);
    $link = expect_ok($http->post("/api/v1/games/{$ctx->upGame}/files/{$ctx->file['id']}/link", ['token' => $ctx->A['token']]));
    check(str_starts_with($link['url'], 'http') && $link['sha256'] === $ctx->file['sha'], 'absolute signed url');
    $r = $http->get($link['url'], ['accept' => '*/*']);
    expect_status($r, 200);
    check(hash('sha256', $r['body']) === $ctx->file['sha'], 'downloaded bytes match SHA-256');
    check(header_value($r, 'accept-ranges') === 'bytes' && str_contains((string) header_value($r, 'content-disposition'), 'attachment'), 'download headers');
    $ctx->link = $link['url'];
    $hist = expect_ok($http->get('/api/v1/user/downloads', ['token' => $ctx->A['token']]));
    check(($hist[0]['file']['id'] ?? 0) === $ctx->file['id'], 'download history');
});
$S->add('التنزيل المصرح به', 'استكمال التنزيل بطلبات Range (206 و 416)', function () use ($http, $ctx) {
    ensure_upload_fixture($http, $ctx);
    $r = $http->get($ctx->link, ['accept' => '*/*', 'headers' => ['Range' => 'bytes=100-199']]);
    expect_status($r, 206);
    check(strlen($r['body']) === 100 && $r['body'] === substr($ctx->file['data'], 100, 100), 'range bytes');
    check(header_value($r, 'content-range') === 'bytes 100-199/' . $ctx->file['size'], 'content-range');
    $r = $http->get($ctx->link, ['accept' => '*/*', 'headers' => ['Range' => 'bytes=-50']]);
    expect_status($r, 206);
    check($r['body'] === substr($ctx->file['data'], -50), 'suffix range');
    $r = $http->get($ctx->link, ['accept' => '*/*', 'headers' => ['Range' => 'bytes=' . ($ctx->file['size'] + 10) . '-']]);
    expect_status($r, 416);
});
$S->add('التنزيل المصرح به', 'صلاحيات الملفات: زائر، حساب غير مؤكد، مدراء فقط', function () use ($http, $ctx) {
    ensure_upload_fixture($http, $ctx);
    expect_status($http->post("/api/v1/games/{$ctx->upGame}/files/{$ctx->file['id']}/link"), 401, 'guest');
    expect_ok($http->put('/api/v1/admin/files/' . $ctx->file['id'], ['token' => $ctx->admin, 'json' => ['access_level' => 'admin']]));
    $r = $http->post("/api/v1/games/{$ctx->upGame}/files/{$ctx->file['id']}/link", ['token' => $ctx->A['token']]);
    expect_status($r, 403, 'admins only');
    expect_status($http->get($ctx->link, ['accept' => '*/*']), 403, 'old signed link re-checks permission');
    expect_ok($http->post("/api/v1/games/{$ctx->upGame}/files/{$ctx->file['id']}/link", ['token' => $ctx->admin]));
    expect_ok($http->put('/api/v1/admin/files/' . $ctx->file['id'], ['token' => $ctx->admin, 'json' => ['access_level' => 'verified']]));
    $u = register($http, $ctx, 'unverified', false);
    expect_status($http->post("/api/v1/games/{$ctx->upGame}/files/{$ctx->file['id']}/link", ['token' => $u['token']]), 403, 'unverified e-mail');
    expect_ok($http->put('/api/v1/admin/files/' . $ctx->file['id'], ['token' => $ctx->admin, 'json' => ['access_level' => 'registered']]));
});
$S->add('التنزيل المصرح به', 'التحقق من سلامة الملف على القرص', function () use ($http, $ctx) {
    ensure_upload_fixture($http, $ctx);
    $v = expect_ok($http->post('/api/v1/admin/files/' . $ctx->file['id'] . '/verify', ['token' => $ctx->admin]));
    check($v['ok'] === true && $v['sha256'] === $ctx->file['sha'], 'verified');
});

// =====================================================================  SECURITY
$S->add('الأمان', 'مسارات الإدارة ترفض الزوار (401) والمستخدمين (403)', function () use ($http, $ctx) {
    expect_status($http->get('/api/v1/admin/stats'), 401);
    expect_status($http->get('/api/v1/admin/stats', ['token' => $ctx->A['token']]), 403);
    expect_status($http->post('/api/v1/admin/uploads/init', ['token' => $ctx->A['token'], 'json' => ['purpose' => 'avatar', 'filename' => 'a.png', 'size' => 10]]), 403);
    expect_status($http->get('/admin', ['accept' => 'text/html']), 302);
});
$S->add('الأمان', 'المشرف يضيف الألعاب لكن لا يحذفها ولا يدير المستخدمين', function () use ($http, $ctx) {
    $r = $http->post('/api/v1/admin/games', ['token' => $ctx->M['token'], 'json' => ['platform_id' => (int) DB::value("SELECT id FROM platforms WHERE slug='ps4'"), 'title' => 'zz-test Mod ' . $ctx->run]]);
    $d = expect_ok($r, 'moderator create', 201);
    $ctx->games[] = (int) $d['id'];
    expect_status($http->delete('/api/v1/admin/games/' . $d['id'], ['token' => $ctx->M['token']]), 403);
    expect_status($http->get('/api/v1/admin/users', ['token' => $ctx->M['token']]), 403);
    expect_status($http->get('/api/v1/admin/uploads/' . bin2hex(random_bytes(16)), ['token' => $ctx->M['token']]), 404);
});
$S->add('الأمان', 'منع تصعيد الصلاحيات: المدير لا يمنح دور المدير الرئيسي', function () use ($http, $ctx) {
    $x = register($http, $ctx, 'adm');
    expect_ok($http->put('/api/v1/admin/users/' . $x['id'], ['token' => $ctx->admin, 'json' => ['role_id' => 2]]));
    $t = login_api($http, $x['username'], $x['password']);
    expect_status($http->put('/api/v1/admin/users/' . $ctx->B['id'], ['token' => $t, 'json' => ['role_id' => 1]]), 403, 'grant super admin');
    expect_status($http->put('/api/v1/admin/users/' . $ctx->adminId, ['token' => $t, 'json' => ['status' => 'suspended']]), 403, 'touch super admin');
    expect_status($http->put('/api/v1/admin/users/' . $x['id'], ['token' => $t, 'json' => ['role_id' => 1]]), 403, 'self promotion');
});
$S->add('الأمان', 'حماية من الإسناد الجماعي في الملف الشخصي', function () use ($http, $ctx) {
    expect_ok($http->put('/api/v1/user/profile', ['token' => $ctx->A['token'], 'json' => ['display_name' => 'Tester', 'role_id' => 1, 'username' => 'hacker', 'status' => 'active', 'can_download' => 1]]));
    $row = DB::one('SELECT role_id, username FROM users WHERE id = ?', [$ctx->A['id']]);
    check((int) $row['role_id'] === 4 && $row['username'] === $ctx->A['username'], 'role/username unchanged');
});
$S->add('الأمان', 'الحساب الموقوف يفقد الوصول فوراً', function () use ($http, $ctx) {
    expect_ok($http->put('/api/v1/admin/users/' . $ctx->B['id'], ['token' => $ctx->admin, 'json' => ['status' => 'suspended']]));
    expect_status($http->get('/api/v1/user/profile', ['token' => $ctx->B['token']]), 401);
    expect_status($http->post('/api/v1/auth/login', ['json' => ['login' => $ctx->B['username'], 'password' => $ctx->B['password']]]), 403);
    expect_ok($http->put('/api/v1/admin/users/' . $ctx->B['id'], ['token' => $ctx->admin, 'json' => ['status' => 'active']]));
    $ctx->B['token'] = login_api($http, $ctx->B['username'], $ctx->B['password']);
});
$S->add('الأمان', 'منع وصول مستخدم لبيانات مستخدم آخر (IDOR)', function () use ($http, $ctx) {
    $mine = expect_ok($http->get('/api/v1/user/tokens', ['token' => $ctx->A['token']]));
    expect_status($http->delete('/api/v1/user/tokens/' . $mine[0]['id'], ['token' => $ctx->B['token']]), 404);
});
$S->add('الأمان', 'العبث برابط التنزيل الموقّع يُرفض', function () use ($http, $ctx) {
    ensure_upload_fixture($http, $ctx);
    $u = parse_url($ctx->link);
    parse_str($u['query'], $q);
    $forged = str_replace('/dl/' . $ctx->file['id'] . '/', '/dl/' . ($ctx->file['id'] + 1) . '/', $ctx->link);
    expect_status($http->get($forged, ['accept' => '*/*']), 403, 'other file id');
    $q['e'] = (string) ((int) $q['e'] + 86400);
    expect_status($http->get(strtok($ctx->link, '?') . '?' . http_build_query($q), ['accept' => '*/*']), 403, 'extended expiry');
    $q['e'] = (string) (time() - 10);
    expect_status($http->get(strtok($ctx->link, '?') . '?' . http_build_query($q), ['accept' => '*/*']), 403, 'expired');
});
$S->add('الأمان', 'CSRF: النماذج وطلبات API بالجلسة تتطلب الرمز', function () use ($base) {
    $h = new Http($base);
    $page = $h->get('/login', ['accept' => 'text/html']);
    $cookie = implode(' | ', $page['headers']['set-cookie'] ?? []);
    check(stripos($cookie, 'HttpOnly') !== false && stripos($cookie, 'SameSite=Lax') !== false, 'session cookie flags');
    expect_status($h->post('/login', ['cookies' => true, 'accept' => 'text/html', 'form' => ['login' => 'x', 'password' => 'y']]), 419, 'missing token');
    preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $page['body'], $m);
    check(!empty($m[1]), 'token rendered');
    $ok = $h->post('/login', ['cookies' => true, 'accept' => 'text/html', 'form' => ['_csrf' => $m[1], 'login' => 'x', 'password' => 'y']]);
    expect_status($ok, 302, 'valid token reaches validation');
});
$S->add('الأمان', 'CSRF على API عند استخدام كعكة الجلسة', function () use ($base, $ctx) {
    $h = new Http($base);
    $page = $h->get('/login', ['accept' => 'text/html']);
    preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $page['body'], $m);
    expect_status($h->post('/login', ['cookies' => true, 'accept' => 'text/html', 'form' => ['_csrf' => $m[1], 'login' => $ctx->A['username'], 'password' => $ctx->A['password']]]), 302);
    $acc = $h->get('/account', ['cookies' => true, 'accept' => 'text/html']);
    expect_status($acc, 200, 'logged in via session');
    preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $acc['body'], $t);
    $gid = (int) DB::value("SELECT id FROM games WHERE product_code = 'DEMO-10002'");
    expect_status($h->post('/api/v1/user/favorites', ['cookies' => true, 'json' => ['game_id' => $gid]]), 419, 'no header');
    expect_ok($h->post('/api/v1/user/favorites', ['cookies' => true, 'json' => ['game_id' => $gid], 'headers' => ['X-CSRF-Token' => $t[1]]]), 'with header', 201);
});
$S->add('الأمان', 'XSS: العناوين تُعرض مهرّبة في الصفحات', function () use ($http, $ctx) {
    $g = create_game($http, $ctx, ['title' => '<script>alert("x")</script> zz-test XSS ' . $ctx->run, 'description' => '<img src=x onerror=alert(1)>']);
    $page = $http->get('/game/' . $g['slug'], ['accept' => 'text/html']);
    expect_status($page, 200);
    check(!str_contains($page['body'], '<script>alert("x")</script>') && !str_contains($page['body'], '<img src=x onerror'), 'raw payload absent');
    check(str_contains($page['body'], '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;'), 'escaped payload present');
    $csp = (string) header_value($page, 'content-security-policy');
    preg_match("/script-src ([^;]+)/", $csp, $sp);
    check(str_contains($sp[1] ?? '', "'self'") && !str_contains($sp[1], "'unsafe-eval'") && !str_contains($sp[1], "'unsafe-inline'") && str_contains($sp[1], "'nonce-"), 'strict nonce-based script CSP: ' . ($sp[1] ?? ''));
});
$S->add('الأمان', 'حقن SQL في البحث والفلاتر والترتيب', function () use ($http) {
    $before = (int) DB::value('SELECT COUNT(*) FROM games');
    $all = expect_ok($http->get('/api/v1/games', ['query' => ['per_page' => 1]]));
    $published = $http->get('/api/v1/games', ['query' => ['per_page' => 1]])['json']['meta']['total'];
    foreach (["' OR 1=1 -- ", '" OR ""="', "1); DROP TABLE games; --", "%' UNION SELECT password_hash FROM users -- ", "\\' OR 'a'='a"] as $q) {
        $r = $http->get('/api/v1/games', ['query' => ['q' => $q]]);
        expect_status($r, 200, 'q=' . $q);
        // the payload is treated as plain search words: it must never turn into an always-true condition
        check($r['json']['meta']['total'] < $published, "payload {$q} widened the result set");
        check(!str_contains($r['body'], '$argon2') && !str_contains($r['body'], 'SQLSTATE'), 'no hash or SQL error leaked');
    }
    expect_ok($http->get('/api/v1/games', ['query' => ['sort' => 'title;DROP TABLE users', 'order' => 'desc,(SELECT 1)', 'platform' => "ps4' OR '1'='1", 'year' => '2004 OR 1=1']]));
    check((int) DB::value('SELECT COUNT(*) FROM games') === $before, 'tables intact');
});
$S->add('الأمان', 'منع رفع الملفات التنفيذية والمتنكرة', function () use ($http, $ctx) {
    ensure_upload_fixture($http, $ctx);
    foreach (['shell.php', 'x.phtml', 'run.sh', 'evil.php.bin', '.htaccess', 'page.html', 'icon.svg'] as $name) {
        $r = $http->post('/api/v1/admin/uploads/init', ['token' => $ctx->admin, 'json' => ['purpose' => 'game_file', 'game_id' => $ctx->upGame, 'filename' => $name, 'size' => 100]]);
        check(in_array($r['status'], [422], true), "{$name} must be rejected (got {$r['status']})");
    }
    $fake = tempnam(sys_get_temp_dir(), 'f');
    file_put_contents($fake, "<?php system(\$_GET['c']); ?>");
    expect_status($http->post("/api/v1/admin/games/{$ctx->upGame}/cover", ['token' => $ctx->admin, 'multipart' => ['file' => new CURLFile($fake, 'image/jpeg', 'evil.jpg')]]), 422, 'php disguised as jpg');
    $poly = make_png(400, 600, "<?php echo 'pwned'; ?>");
    $d = expect_ok($http->post("/api/v1/admin/games/{$ctx->upGame}/cover", ['token' => $ctx->admin, 'multipart' => ['file' => new CURLFile($poly, 'image/png', 'poly.png')]]));
    foreach ($d['urls'] as $u) {
        $img = $http->get($u, ['accept' => '*/*']);
        check(!str_contains($img['body'], '<?php'), 'payload stripped by re-encoding');
    }
});
$S->add('الأمان', 'الحد من حجم الرفع (413)', function () use ($http, $ctx) {
    ensure_upload_fixture($http, $ctx);
    Settings::set('uploads.max_file_gb', 1);
    try {
        expect_status($http->post('/api/v1/admin/uploads/init', ['token' => $ctx->admin, 'json' => ['purpose' => 'game_file', 'game_id' => $ctx->upGame, 'filename' => 'huge.iso', 'size' => 2 * 1024 ** 3]]), 413);
    } finally {
        Settings::set('uploads.max_file_gb', 200);
    }
});
$S->add('الأمان', 'لا تنفيذ لملفات PHP داخل مجلد الوسائط', function () use ($http) {
    $f = PUBLIC_PATH . '/media/zz-test-exec.php';
    file_put_contents($f, "<?php echo 'EXECUTED';");
    try {
        $r = $http->get('/media/zz-test-exec.php', ['accept' => '*/*']);
        check(!str_contains($r['body'], 'EXECUTED') && $r['status'] >= 400, 'script not executed (' . $r['status'] . ')');
    } finally {
        @unlink($f);
    }
});
$S->add('الأمان', 'لا وصول لملفات الإعدادات والتخزين عبر الويب', function () use ($http) {
    foreach (['/../config/config.php', '/config/config.php', '/storage/logs/', '/app/routes.php', '/dl/1/..%2F..%2Fconfig%2Fconfig.php?u=0&e=9999999999&s=' . str_repeat('a', 40), '/.env'] as $p) {
        $r = $http->get($p, ['accept' => '*/*']);
        check($r['status'] >= 400, "{$p} → {$r['status']}");
        check(!str_contains($r['body'], "'password'"), "{$p} leaked config");
    }
});
$S->add('الأمان', 'ترويسات الأمان على الصفحات والـ API', function () use ($http) {
    $page = $http->get('/', ['accept' => 'text/html']);
    foreach (['x-frame-options' => 'DENY', 'x-content-type-options' => 'nosniff', 'referrer-policy' => 'strict-origin-when-cross-origin'] as $h => $v) {
        check(header_value($page, $h) === $v, "{$h} header");
    }
    check(str_contains((string) header_value($page, 'content-security-policy'), "frame-ancestors 'none'"), 'CSP');
    check(header_value($page, 'x-powered-by') === null, 'X-Powered-By hidden');
    $api = $http->get('/api/v1/status');
    check(str_contains((string) header_value($api, 'content-security-policy'), "default-src 'none'"), 'API CSP');
});
$S->add('الأمان', 'كلمات المرور Argon2id والرموز مخزنة كبصمات فقط', function () use ($ctx) {
    $hash = (string) DB::value('SELECT password_hash FROM users WHERE id = ?', [$ctx->A['id']]);
    check(str_starts_with($hash, defined('PASSWORD_ARGON2ID') ? '$argon2id$' : '$2y$'), 'modern hash');
    check(DB::value('SELECT COUNT(*) FROM api_tokens WHERE token_hash = ?', [$ctx->A['token']]) === 0, 'plain token never stored');
    check(DB::value('SELECT COUNT(*) FROM api_tokens WHERE token_hash = ?', [hash('sha256', $ctx->A['token'])]) === 1, 'sha-256 stored');
});
$S->add('الأمان', 'استعادة كلمة المرور لا تكشف وجود البريد', function () use ($http, $ctx) {
    $a = $http->post('/api/v1/auth/forgot-password', ['json' => ['email' => $ctx->A['email']]]);
    $b = $http->post('/api/v1/auth/forgot-password', ['json' => ['email' => 'nobody-' . $ctx->run . '@example.com']]);
    check($a['status'] === $b['status'] && $a['body'] === $b['body'], 'identical responses');
});
$S->add('الأمان', 'حد معدل الطلبات العام (429 مع Retry-After)', function () use ($http, $ctx) {
    Settings::set('api.rate_limit_per_minute', 15);
    try {
        $u = register($http, $ctx, 'rl');
        $limited = null;
        for ($i = 0; $i < 25; $i++) {
            $r = $http->get('/api/v1/categories', ['token' => $u['token']]);
            if ($r['status'] === 429) {
                $limited = $r;
                break;
            }
        }
        check($limited !== null && header_value($limited, 'retry-after') !== null, 'rate limited');
    } finally {
        Settings::set('api.rate_limit_per_minute', 5000);
    }
});
$S->add('الأمان', 'حماية تسجيل الدخول من التخمين (429 حسب العنوان)', function () use ($http, $ctx) {
    $hit = false;
    for ($i = 0; $i < 20; $i++) {
        $r = $http->post('/api/v1/auth/login', ['json' => ['login' => 'nobody-' . $ctx->run, 'password' => 'x' . $i]]);
        if ($r['status'] === 429) {
            $hit = true;
            break;
        }
    }
    check($hit, 'login throttled');
    Cache::flush();
});

// =====================================================================  ADMIN API (everything the web panel does — used by the Android app)
$S->add('واجهة الإدارة الموسعة', 'الإعدادات: قراءة المجموعات حسب الصلاحية وحفظ جزئي مع التحقق', function () use ($http, $ctx) {
    $d = expect_ok($http->get('/api/v1/admin/settings', ['token' => $ctx->admin]));
    check(isset($d['groups']['general'], $d['groups']['security'], $d['groups']['api'], $d['groups']['backups']), 'all groups for super admin');
    $pw = array_values(array_filter($d['groups']['general'], static fn ($f) => $f['key'] === 'mail.password'))[0];
    check($pw['value'] === null && array_key_exists('is_set', $pw), 'secret never returned');
    expect_status($http->get('/api/v1/admin/settings', ['token' => $ctx->M['token']]), 403, 'moderator');
    $old = Settings::get('site.tagline');
    $r = expect_ok($http->put('/api/v1/admin/settings/general', ['token' => $ctx->admin, 'json' => ['site.tagline' => 'zz-test tagline']]));
    Settings::forget(); // this process keeps its own copy of the settings
    check(array_keys($r['saved']) === ['site.tagline'] && Settings::get('site.tagline') === 'zz-test tagline', 'partial save touches only sent keys');
    expect_status($http->put('/api/v1/admin/settings/general', ['token' => $ctx->admin, 'json' => ['site_per_page' => 5000]]), 422, 'out of range');
    expect_status($http->put('/api/v1/admin/settings/nope', ['token' => $ctx->admin, 'json' => []]), 404, 'unknown group');
    expect_ok($http->put('/api/v1/admin/settings/general', ['token' => $ctx->admin, 'json' => ['site_tagline' => (string) $old]]));
    $mail = expect_ok($http->post('/api/v1/admin/settings/mail-test', ['token' => $ctx->admin]));
    check($mail['driver'] === 'log', 'mail test via log driver');
});
$S->add('واجهة الإدارة الموسعة', 'الأدوار: قراءة المصفوفة وحفظها للمدير الرئيسي فقط', function () use ($http, $ctx) {
    $d = expect_ok($http->get('/api/v1/admin/roles', ['token' => $ctx->admin]));
    check(count($d['roles']) === 4 && count($d['permissions']) >= 26 && $d['can_edit'] === true, 'matrix');
    $saved = expect_ok($http->put('/api/v1/admin/roles', ['token' => $ctx->admin, 'json' => ['matrix' => $d['matrix']]]));
    check($saved['matrix'] == $d['matrix'], 'unchanged after saving the same matrix');
    $x = register($http, $ctx, 'adm2');
    expect_ok($http->put('/api/v1/admin/users/' . $x['id'], ['token' => $ctx->admin, 'json' => ['role_id' => 2]]));
    $t = login_api($http, $x['username'], $x['password']);
    expect_status($http->put('/api/v1/admin/roles', ['token' => $t, 'json' => ['matrix' => $d['matrix']]]), 403, 'admin (not super) cannot edit');
});
$S->add('واجهة الإدارة الموسعة', 'إنشاء حساب من التطبيق مع منع تجاوز الدور', function () use ($http, $ctx) {
    $u = expect_ok($http->post('/api/v1/admin/users', ['token' => $ctx->admin, 'json' => ['username' => 'zz-test-' . $ctx->run . '-new', 'email' => 'zz-test-' . $ctx->run . '-new@example.com', 'password' => 'Created-pass-1', 'role_id' => 3]]), 'create', 201);
    $ctx->users[] = (int) $u['id'];
    check($u['role']['slug'] === 'moderator' && $u['email_verified'] === true && $u['role']['id'] === 3, 'created as moderator');
    expect_status($http->post('/api/v1/admin/users', ['token' => $ctx->admin, 'json' => ['username' => 'zz-test-' . $ctx->run . '-new', 'email' => 'other' . $ctx->run . '@example.com', 'password' => 'Created-pass-1']]), 422, 'duplicate');
    expect_status($http->post('/api/v1/admin/users', ['token' => $ctx->M['token'], 'json' => ['username' => 'zz-x' . $ctx->run, 'email' => 'zx' . $ctx->run . '@example.com', 'password' => 'Created-pass-1']]), 403, 'moderator');
    $detail = expect_ok($http->get('/api/v1/admin/users/' . $ctx->A['id'], ['token' => $ctx->admin]));
    check(isset($detail['devices'], $detail['recent_activity'], $detail['recent_downloads']) && array_key_exists('locked', $detail), 'detailed user view');
});
$S->add('واجهة الإدارة الموسعة', 'رموز API: عرض كل الأجهزة وإلغاء رمز', function () use ($http, $ctx) {
    $u = register($http, $ctx, 'tok');
    $list = expect_ok($http->get('/api/v1/admin/tokens', ['token' => $ctx->admin]));
    check(count(array_filter($list, static fn ($t) => $t['is_current'])) === 1, 'current token flagged');
    $mine = array_values(array_filter($list, static fn ($t) => $t['user']['id'] === $u['id']))[0] ?? null;
    check($mine !== null, 'user token listed');
    expect_ok($http->delete('/api/v1/admin/tokens/' . $mine['id'], ['token' => $ctx->admin]));
    expect_status($http->get('/api/v1/user/profile', ['token' => $u['token']]), 401, 'revoked token');
    expect_status($http->get('/api/v1/admin/tokens', ['token' => $ctx->M['token']]), 403, 'moderator');
});
$S->add('واجهة الإدارة الموسعة', 'الأمان والملفات والتخزين والطابور والسجلات', function () use ($http, $ctx) {
    ensure_upload_fixture($http, $ctx);
    $sec = expect_ok($http->get('/api/v1/admin/security', ['token' => $ctx->admin]));
    check(isset($sec['locked_users'], $sec['recent_failures'], $sec['health']['database']), 'security overview');
    $f = $http->get('/api/v1/admin/files', ['token' => $ctx->admin, 'query' => ['q' => 'zz-', 'per_page' => 50]]);
    $files = expect_ok($f);
    check($f['json']['meta']['total'] >= 1 && isset($files[0]['game']['title'], $files[0]['disk']), 'global files list');
    check(is_array(expect_ok($http->get('/api/v1/admin/storage/orphans', ['token' => $ctx->admin]))), 'orphans');
    check(expect_ok($http->post('/api/v1/admin/storage/cache', ['token' => $ctx->admin]))['cleared'] === true, 'cache cleared');
    check(array_key_exists('cleaned_uploads', expect_ok($http->post('/api/v1/admin/storage/cleanup', ['token' => $ctx->admin]))), 'cleanup');
    expect_status($http->post('/api/v1/admin/storage/format', ['token' => $ctx->admin]), 404, 'unknown action');
    check(isset(expect_ok($http->post('/api/v1/admin/queue/run', ['token' => $ctx->admin]))['queue']), 'queue run');
    $errs = expect_ok($http->get('/api/v1/admin/logs/errors', ['token' => $ctx->admin, 'query' => ['level' => 'warning']]));
    check(is_array($errs) && (!$errs || $errs[0]['level'] === 'warning'), 'error log filtered');
    expect_status($http->get('/api/v1/admin/files', ['token' => $ctx->M['token']]), 403, 'moderator files');
});
$S->add('واجهة الإدارة الموسعة', 'النسخ الاحتياطي: إنشاء وتنزيل وحذف، والاستعادة تتطلب كلمة المرور', function () use ($http, $ctx) {
    $b = expect_ok($http->post('/api/v1/admin/backups', ['token' => $ctx->admin, 'json' => ['include_media' => false]]), 'create', 201);
    $list = expect_ok($http->get('/api/v1/admin/backups', ['token' => $ctx->admin]));
    check(in_array($b['name'], array_column($list, 'name'), true), 'listed');
    $dl = $http->get('/api/v1/admin/backups/' . $b['name'] . '/download', ['token' => $ctx->admin, 'accept' => '*/*', 'encoding' => 'identity']);
    expect_status($dl, 200, 'download');
    check(str_starts_with($dl['body'], "\x1f\x8b") && strlen($dl['body']) === $b['size'], 'gzip backup bytes');
    expect_status($http->post('/api/v1/admin/backups/' . $b['name'] . '/restore', ['token' => $ctx->admin, 'json' => ['password' => 'wrong-password-1']]), 422, 'wrong password');
    expect_status($http->get('/api/v1/admin/backups/../../config/config.php/download', ['token' => $ctx->admin]), 404, 'path traversal');
    expect_ok($http->delete('/api/v1/admin/backups/' . $b['name'], ['token' => $ctx->admin]));
    check(!in_array($b['name'], array_column(expect_ok($http->get('/api/v1/admin/backups', ['token' => $ctx->admin])), 'name'), true), 'deleted');
    expect_status($http->get('/api/v1/admin/backups', ['token' => $ctx->M['token']]), 403, 'moderator');
});
$S->add('واجهة الإدارة الموسعة', 'إجراءات جماعية وترتيب الصور وإزالة الفيديو وحذف إشعار', function () use ($http, $ctx) {
    $a = create_game($http, $ctx, ['title' => 'zz-test Bulk A ' . $ctx->run]);
    $b = create_game($http, $ctx, ['title' => 'zz-test Bulk B ' . $ctx->run]);
    $r = expect_ok($http->post('/api/v1/admin/games/bulk', ['token' => $ctx->admin, 'json' => ['ids' => [$a['id'], $b['id'], 99999999], 'action' => 'hide']]));
    check($r['done'] === 2 && $r['missing'] === [99999999], 'bulk hide');
    expect_status($http->get('/api/v1/games/' . $a['id']), 404, 'hidden');
    expect_ok($http->post('/api/v1/admin/games/bulk', ['token' => $ctx->admin, 'json' => ['ids' => [$a['id']], 'action' => 'publish']]));
    expect_ok($http->get('/api/v1/games/' . $a['id']));
    expect_status($http->post('/api/v1/admin/games/bulk', ['token' => $ctx->M['token'], 'json' => ['ids' => [$a['id']], 'action' => 'delete']]), 403, 'moderator cannot bulk delete');
    expect_status($http->post('/api/v1/admin/games/bulk', ['token' => $ctx->admin, 'json' => ['ids' => [$a['id']], 'action' => 'explode']]), 422, 'unknown action');
    $s1 = expect_ok($http->post("/api/v1/admin/games/{$a['id']}/screenshots", ['token' => $ctx->admin, 'multipart' => ['file' => new CURLFile(make_png(800, 450), 'image/png', 'a.png')]]), 's1', 201);
    $s2 = expect_ok($http->post("/api/v1/admin/games/{$a['id']}/screenshots", ['token' => $ctx->admin, 'multipart' => ['file' => new CURLFile(make_png(800, 450), 'image/png', 'b.png')]]), 's2', 201);
    expect_ok($http->post("/api/v1/admin/games/{$a['id']}/screenshots/reorder", ['token' => $ctx->admin, 'json' => ['ids' => [$s2['id'], $s1['id']]]]));
    $g = expect_ok($http->get("/api/v1/admin/games/{$a['id']}", ['token' => $ctx->admin]));
    check(array_column($g['screenshots'], 'id') === [$s2['id'], $s1['id']], 'new order');
    expect_ok($http->put("/api/v1/admin/games/{$a['id']}", ['token' => $ctx->admin, 'json' => ['trailer_url' => 'https://vimeo.com/76979871']]));
    expect_ok($http->delete("/api/v1/admin/games/{$a['id']}/trailer", ['token' => $ctx->admin]));
    check(expect_ok($http->get("/api/v1/admin/games/{$a['id']}", ['token' => $ctx->admin]))['trailer'] === null, 'trailer removed');
    $n = expect_ok($http->post('/api/v1/admin/notifications', ['token' => $ctx->admin, 'json' => ['title' => 'zz-test note ' . $ctx->run, 'audience' => 'user', 'user' => $ctx->B['username']]]), 'notify', 201);
    expect_ok($http->delete('/api/v1/admin/notifications/' . $n['id'], ['token' => $ctx->admin]));
    $mine = expect_ok($http->get('/api/v1/user/notifications', ['token' => $ctx->B['token']]));
    check(!in_array('zz-test note ' . $ctx->run, array_column($mine, 'title'), true), 'notification deleted');
    expect_ok($http->post('/api/v1/admin/games/bulk', ['token' => $ctx->admin, 'json' => ['ids' => [$a['id'], $b['id']], 'action' => 'delete']]));
    expect_status($http->get('/api/v1/admin/games/' . $b['id'], ['token' => $ctx->admin]), 404, 'bulk deleted');
});

// =====================================================================  WEB PAGES
// PHP's own error output (display_errors is on in development); the error-log page may legitimately show logged messages.
const PHP_ERROR_RE = '#<b>(Warning|Notice|Fatal error|Deprecated|Parse error)</b>:|^(Warning|Fatal error|Notice): #m';
$pages = ['/', '/platforms', '/platform/ps5', '/platform/ps1', '/games', '/games?q=moon&sort=rating', '/categories', '/category/horror', '/updates', '/login', '/register', '/forgot-password'];
$S->add('صفحات الموقع', 'كل الصفحات العامة تعمل (200)', function () use ($http, $pages) {
    foreach ($pages as $p) {
        $r = $http->get($p, ['accept' => 'text/html']);
        expect_status($r, 200, $p);
        check(!preg_match(PHP_ERROR_RE, $r['body']), "{$p} has PHP errors");
    }
});
$S->add('صفحات الموقع', 'صفحات الحساب ولوحة التحكم تعمل بعد الدخول', function () use ($base, $adminUser, $adminPass) {
    $h = new Http($base);
    $page = $h->get('/login', ['accept' => 'text/html']);
    preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $page['body'], $m);
    expect_status($h->post('/login', ['cookies' => true, 'accept' => 'text/html', 'form' => ['_csrf' => $m[1], 'login' => $adminUser, 'password' => $adminPass]]), 302);
    $gid = (int) DB::value("SELECT id FROM games WHERE product_code = 'DEMO-40004'");
    $uid = (int) DB::value('SELECT id FROM users ORDER BY id LIMIT 1');
    foreach (['/account', '/account/edit', '/account/favorites', '/account/history', '/account/downloads', '/account/updates', '/account/notifications', '/account/devices',
                 '/admin', '/admin/games', '/admin/games/create', "/admin/games/{$gid}/edit", '/admin/import', '/admin/duplicates', '/admin/platforms', '/admin/platforms/1/edit',
                 '/admin/categories', '/admin/users', "/admin/users/{$uid}", '/admin/roles', '/admin/updates', '/admin/notifications', '/admin/uploads', '/admin/files',
                 '/admin/downloads', '/admin/storage', '/admin/storage?orphans=1', '/admin/api', '/admin/security', '/admin/logs', '/admin/logs/errors', '/admin/backups', '/admin/settings'] as $p) {
        $r = $h->get($p, ['cookies' => true, 'accept' => 'text/html']);
        expect_status($r, 200, $p);
        check(!preg_match(PHP_ERROR_RE, $r['body']), "{$p} has PHP errors");
    }
});

// =====================================================================  RUN + CLEANUP
$ok = $S->run($opt['filter'] ?? null);
$cleanup();

if (!empty($opt['report'])) {
    file_put_contents($opt['report'], json_encode(['date' => gmdate('c'), 'base' => $base, 'results' => array_map(static fn ($r) => ['group' => $r[0], 'name' => $r[1], 'passed' => $r[2], 'error' => $r[3], 'ms' => round($r[4])], $S->results)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
exit($ok ? 0 : 1);
