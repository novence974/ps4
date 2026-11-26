<?php
use App\Core\Auth;
use App\Core\Session;

$u = Auth::user();
$prefs = json_decode((string) ($u['preferences'] ?? ''), true) ?: [];
$theme = $prefs['theme'] ?? (string) setting('site.default_theme', 'dark');
$toast = Session::getFlash('toast');
$nav = [
    ['', [['/admin', 'gauge', 'لوحة التحكم', 'admin.access']]],
    ['المكتبة', [
        ['/admin/games', 'library', 'الألعاب', 'admin.access'],
        ['/admin/games/create', 'plus', 'إضافة لعبة', 'games.create'],
        ['/admin/import', 'file-sheet', 'الاستيراد بالجملة', 'games.import'],
        ['/admin/duplicates', 'compare', 'الألعاب المكررة', 'games.edit'],
        ['/admin/platforms', 'gamepad', 'الأجهزة', 'platforms.manage'],
        ['/admin/categories', 'tag', 'التصنيفات', 'categories.manage'],
        ['/admin/updates', 'sparkles', 'التحديثات', 'updates.manage'],
    ]],
    ['الملفات', [
        ['/admin/uploads', 'cloud-upload', 'مدير الرفع', 'files.upload|files.manage'],
        ['/admin/files', 'files', 'الملفات', 'files.manage'],
        ['/admin/downloads', 'download', 'التنزيلات', 'downloads.view'],
        ['/admin/storage', 'drive', 'التخزين', 'storage.manage'],
    ]],
    ['المستخدمون', [
        ['/admin/users', 'users', 'المستخدمون', 'users.view|users.manage'],
        ['/admin/roles', 'user-cog', 'الأدوار والصلاحيات', 'roles.assign'],
        ['/admin/notifications', 'bell-ring', 'الإشعارات', 'notifications.send'],
    ]],
    ['النظام', [
        ['/admin/api', 'server', 'إعدادات API', 'api.manage'],
        ['/admin/security', 'shield', 'الأمان', 'security.manage'],
        ['/admin/logs', 'activity', 'سجل النشاط', 'logs.view'],
        ['/admin/logs/errors', 'alert', 'سجل الأخطاء', 'logs.view'],
        ['/admin/backups', 'archive', 'النسخ الاحتياطي', 'backups.manage'],
        ['/admin/settings', 'settings', 'الإعدادات', 'settings.manage'],
    ]],
];
$path = App\Core\Request::$current?->path() ?? '';
$allowed = static function (string $perms): bool { foreach (explode('|', $perms) as $p) { if (can($p)) { return true; } } return false; };
?><!doctype html>
<html lang="ar" dir="rtl" data-theme="<?= e($theme === 'light' ? 'light' : 'dark') ?>" data-theme-pref="<?= e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? 'لوحة التحكم') . ' — ' . setting('site.name')) ?></title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="<?= e(asset('img/brand/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preload" href="<?= e(asset('fonts/readex-pro.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<script nonce="<?= e(nonce()) ?>">(function(){try{var d=document.documentElement,p=localStorage.getItem('upsl-theme')||d.getAttribute('data-theme-pref');if(p==='auto'){p=matchMedia('(prefers-color-scheme: light)').matches?'light':'dark';}if(p==='light'||p==='dark'){d.setAttribute('data-theme',p);}}catch(e){}})();</script>
</head>
<body class="admin" data-base="<?= e(rtrim(url(''), '/')) ?>" data-auth="1" data-sfx="<?= setting('sfx.enabled', true) ? '1' : '0' ?>">
<div class="ambient" aria-hidden="true"></div>
<div class="shell">
  <aside class="side glass-strong" id="side" aria-label="قائمة لوحة التحكم">
    <a class="brand" href="<?= e(url('/admin')) ?>"><img src="<?= e(asset('img/brand/logo.svg')) ?>" alt="" width="34" height="34"><b><span>ULTIMATE</span> PS</b><small class="tag">ADMIN</small></a>
    <nav>
      <?php foreach ($nav as [$group, $items]):
          $visible = array_filter($items, static fn ($i) => $allowed($i[3]));
          if (!$visible) { continue; } ?>
        <?php if ($group !== ''): ?><div class="side-group"><?= e($group) ?></div><?php endif; ?>
        <?php foreach ($visible as [$href, $ic, $label]):
            $on = $href === '/admin' ? $path === '/admin' : ($path === $href || (str_starts_with($path, $href . '/') && !($href === '/admin/games' && str_starts_with($path, '/admin/games/create')) && !($href === '/admin/logs' && str_starts_with($path, '/admin/logs/errors')))); ?>
          <a href="<?= e(url($href)) ?>" class="<?= $on ? 'on' : '' ?>" <?= $on ? 'aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <a href="<?= e(url('/')) ?>"><?= icon('external') ?><span>عرض الموقع</span></a>
    </div>
  </aside>
  <div class="admin-main">
    <header class="admin-top">
      <button class="icon-btn side-toggle" type="button" data-side-toggle aria-controls="side" aria-label="القائمة"><?= icon('menu') ?></button>
      <h1><?= e($title ?? 'لوحة التحكم') ?></h1>
      <span class="spacer"></span>
      <form class="admin-search" action="<?= e(url('/admin/games')) ?>" method="get" role="search">
        <label class="sr-only" for="aq">بحث في الألعاب</label>
        <input id="aq" type="search" name="q" placeholder="ابحث في الألعاب…" value="<?= e((string) (App\Core\Request::$current?->query('q') ?? '')) ?>" maxlength="100">
      </form>
      <button class="icon-btn" type="button" data-theme-toggle aria-label="تبديل المظهر"><?= icon('moon') ?></button>
      <div class="menu" data-menu>
        <button class="avatar-btn" type="button" data-menu-btn aria-haspopup="true" aria-expanded="false" aria-label="الحساب"><img class="avatar" src="<?= e(avatar_url($u)) ?>" alt="" width="38" height="38"></button>
        <div class="dropdown glass-strong" hidden>
          <div class="who"><b><?= e(user_label($u)) ?></b><small><?= e($u['role_name']) ?></small></div><hr>
          <a href="<?= e(url('/account')) ?>"><?= icon('user') ?>حسابي</a>
          <a href="<?= e(url('/')) ?>"><?= icon('home') ?>الموقع</a><hr>
          <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button type="submit"><?= icon('logout') ?>تسجيل الخروج</button></form>
        </div>
      </div>
    </header>
    <div class="admin-content">
      <?= $content ?>
    </div>
  </div>
</div>
<div class="toasts" id="toasts" aria-live="polite">
  <?php if (is_array($toast)): ?>
    <div class="toast glass-strong <?= e($toast['type'] ?? 'info') ?>" data-auto><?= icon(($toast['type'] ?? '') === 'error' ? 'err' : (($toast['type'] ?? '') === 'success' ? 'ok' : 'info')) ?><span><?= e($toast['message'] ?? '') ?></span><button type="button" aria-label="إغلاق" data-close><?= icon('x') ?></button></div>
  <?php endif; ?>
</div>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<script src="<?= e(asset('js/audio.js')) ?>" defer></script>
<script src="<?= e(asset('js/uploader.js')) ?>" defer></script>
<script src="<?= e(asset('js/admin.js')) ?>" defer></script>
</body>
</html>
