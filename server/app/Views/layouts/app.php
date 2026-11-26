<?php
use App\Core\Auth;
use App\Core\Session;
use App\Services\NotificationService;

$u = Auth::user();
$siteName = (string) setting('site.name');
$prefs = $u ? (json_decode((string) ($u['preferences'] ?? ''), true) ?: []) : [];
$theme = $prefs['theme'] ?? (string) setting('site.default_theme', 'dark');
$toast = Session::started() ? Session::getFlash('toast') : null;
$unread = 0;
if ($u) {
    try { $unread = NotificationService::unreadCount($u); } catch (Throwable) { $unread = 0; }
}
$track = (string) setting('music.track', '');
$musicSrc = $track !== '' ? url($track) : asset('audio/night-shelf.mp3');
$pageTitle = isset($title) && $title !== $siteName ? $title . ' — ' . $siteName : $siteName;
$navItems = [['/', 'home', 'الرئيسية'], ['/platforms', 'gamepad', 'الأجهزة'], ['/games', 'grid', 'تصفح'], ['/categories', 'tag', 'التصنيفات'], ['/updates', 'sparkles', 'التحديثات']];
?><!doctype html>
<html lang="ar" dir="rtl" data-theme="<?= e($theme === 'light' ? 'light' : 'dark') ?>" data-theme-pref="<?= e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($metaDescription ?? setting('site.tagline')) ?>">
<meta name="theme-color" content="#070a1c">
<?php if (setting('site.owner')): ?><meta name="author" content="<?= e(setting('site.owner')) ?>"><meta name="copyright" content="© <?= gmdate('Y') ?> <?= e(setting('site.owner')) ?>"><?php endif; ?>
<script nonce="<?= e(nonce()) ?>">
/* tag the device so CSS can fine-tune (layout itself follows screen size & input type) */
(function (d, n) {
  var ua = n.userAgent || '', os = /iPad|iPhone|iPod/.test(ua) || (n.platform === 'MacIntel' && n.maxTouchPoints > 1) ? 'ios'
    : /Android/.test(ua) ? 'android' : 'desktop';
  d.dataset.os = os;
  d.dataset.input = matchMedia('(pointer: coarse)').matches ? 'touch' : 'mouse';
  if (matchMedia('(display-mode: standalone)').matches || n.standalone) d.dataset.standalone = '1';
  /* browser bar colour follows the PlayStation era of the page */
  addEventListener('DOMContentLoaded', function () {
    var h = document.querySelector('.gen-hero[data-gen]'), m = document.querySelector('meta[name="theme-color"]');
    var c = { ps1: '#1d1b19', ps2: '#050a26', ps3: '#0b0b0e', ps4: '#031131', ps5: '#070912' };
    if (h && m && c[h.dataset.gen]) m.content = c[h.dataset.gen];
  });
})(document.documentElement, navigator);
</script>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<link rel="icon" href="<?= e(asset('img/brand/favicon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('img/brand/icon-192.png')) ?>">
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<link rel="preload" href="<?= e(asset('fonts/readex-pro.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?php foreach (($extraCss ?? []) as $css): ?><link rel="stylesheet" href="<?= e(asset($css)) ?>"><?php endforeach; ?>
<script nonce="<?= e(nonce()) ?>">(function(){try{var d=document.documentElement,p=localStorage.getItem('upsl-theme')||d.getAttribute('data-theme-pref');if(p==='auto'){p=matchMedia('(prefers-color-scheme: light)').matches?'light':'dark';}if(p==='light'||p==='dark'){d.setAttribute('data-theme',p);}}catch(e){}})();</script>
</head>
<body class="<?= e($bodyClass ?? '') ?>" data-base="<?= e(rtrim(url(''), '/')) ?>" data-auth="<?= $u ? '1' : '0' ?>"
      data-music="<?= setting('music.enabled', true) ? '1' : '0' ?>" data-sfx="<?= setting('sfx.enabled', true) ? '1' : '0' ?>"
      data-volume="<?= (int) ($prefs['volume'] ?? setting('music.default_volume', 35)) ?>">
<div class="ambient" aria-hidden="true"></div>
<a class="sr-only" href="#main">تخطَّ إلى المحتوى</a>

<header class="topbar">
  <div class="wrap">
    <a class="brand" href="<?= e(url('/')) ?>" aria-label="<?= e($siteName) ?>">
      <img src="<?= e(asset('img/brand/logo.svg')) ?>" alt="" width="36" height="36">
      <b><span>ULTIMATE</span> PS LIBRARY</b>
    </a>
    <nav class="nav" aria-label="التنقل الرئيسي">
      <?php foreach ($navItems as [$href, $ic, $label]): ?>
        <a href="<?= e(url($href)) ?>" class="<?= is_active_path(['/platforms' => '/platform', '/games' => '/game', '/categories' => '/categor'][$href] ?? $href) ? 'active' : '' ?>"<?= is_active_path(['/platforms' => '/platform', '/games' => '/game', '/categories' => '/categor'][$href] ?? $href) ? ' aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <form class="search" action="<?= e(url('/games')) ?>" method="get" role="search" data-suggest>
      <label class="sr-only" for="q">ابحث عن لعبة</label>
      <input id="q" type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="ابحث باسم اللعبة أو الشركة أو الرمز…" autocomplete="off" maxlength="100">
      <?= icon('search') ?>
      <div class="suggest glass-strong" hidden role="listbox"></div>
    </form>
    <div class="tools">
      <button class="icon-btn hide-sm" type="button" data-theme-toggle aria-label="تبديل الوضع الداكن والفاتح" title="الوضع الداكن / الفاتح"><?= icon('moon') ?></button>
      <?php if ($u): ?>
        <div class="menu" data-menu>
          <button class="icon-btn" type="button" data-menu-btn data-notif-btn aria-haspopup="true" aria-expanded="false" aria-label="الإشعارات">
            <?= icon('bell') ?><?php if ($unread): ?><span class="dot" data-unread><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?>
          </button>
          <div class="dropdown notif-panel glass-strong" hidden>
            <div class="head"><b>الإشعارات</b>
              <form method="post" action="<?= e(url('/account/notifications/read')) ?>" data-ajax="read-all"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" type="submit">تعليم الكل كمقروء</button></form>
            </div>
            <div data-notif-body><p class="muted" style="padding:10px">جارٍ التحميل…</p></div>
            <hr><a href="<?= e(url('/account/notifications')) ?>"><?= icon('list') ?>كل الإشعارات</a>
          </div>
        </div>
        <div class="menu" data-menu>
          <button class="avatar-btn" type="button" data-menu-btn aria-haspopup="true" aria-expanded="false" aria-label="قائمة الحساب">
            <img class="avatar" src="<?= e(avatar_url($u)) ?>" alt="" width="38" height="38">
          </button>
          <div class="dropdown glass-strong" hidden>
            <div class="who"><b><?= e(user_label($u)) ?></b><small><?= e($u['role_name']) ?></small></div>
            <hr>
            <a href="<?= e(url('/account')) ?>"><?= icon('user') ?>حسابي</a>
            <a href="<?= e(url('/account/favorites')) ?>"><?= icon('heart') ?>المفضلة</a>
            <a href="<?= e(url('/account/downloads')) ?>"><?= icon('download') ?>سجل التنزيلات</a>
            <a href="<?= e(url('/account/edit')) ?>"><?= icon('settings') ?>إعدادات الحساب</a>
            <?php if (can('admin.access')): ?><a href="<?= e(url('/admin')) ?>"><?= icon('gauge') ?>لوحة التحكم</a><?php endif; ?>
            <button type="button" data-theme-toggle><?= icon('sun') ?>تبديل المظهر</button>
            <hr>
            <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button type="submit"><?= icon('logout') ?>تسجيل الخروج</button></form>
          </div>
        </div>
      <?php else: ?>
        <a class="btn btn-sm btn-ghost hide-sm" href="<?= e(url('/login')) ?>">تسجيل الدخول</a>
        <a class="btn btn-sm btn-primary" href="<?= e(url('/register')) ?>">إنشاء حساب</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<main id="main" tabindex="-1">
<?= $content ?>
</main>

<footer class="footer">
  <div class="wrap">
    <div>
      <a class="brand" href="<?= e(url('/')) ?>"><img src="<?= e(asset('img/brand/logo.svg')) ?>" alt="" width="30" height="30"><b><span>ULTIMATE</span> PS LIBRARY</b></a>
      <?php if (setting('site.owner')): ?><p class="copyright">© <?= gmdate('Y') ?> <b dir="ltr"><?= e(setting('site.owner')) ?></b> — جميع الحقوق محفوظة</p><?php endif; ?>
      <?php if ($tg = telegram_url()): ?>
        <a class="btn btn-sm tg-contact" href="<?= e($tg) ?>" target="_blank" rel="noopener"><?= icon('send') ?>تواصل معي على تلغرام <span dir="ltr">@<?= e(substr($tg, 13)) ?></span></a>
      <?php endif; ?>
    </div>
    <nav aria-label="روابط التذييل">
      <a href="<?= e(url('/platforms')) ?>">الأجهزة</a>
      <a href="<?= e(url('/games?sort=rating')) ?>">الأعلى تقييماً</a>
      <a href="<?= e(url('/games?sort=downloads')) ?>">الأكثر تحميلاً</a>
      <a href="<?= e(url('/updates')) ?>">التحديثات</a>
      <a href="<?= e(url('/api/v1/status')) ?>">حالة الخادم</a>
    </nav>
  </div>
</footer>

<nav class="tabbar" aria-label="تنقل الجوال">
  <a href="<?= e(url('/')) ?>" class="<?= is_active_path('/') ? 'active' : '' ?>"><?= icon('home') ?>الرئيسية</a>
  <a href="<?= e(url('/platforms')) ?>" class="<?= is_active_path('/platform') ? 'active' : '' ?>"><?= icon('gamepad') ?>الأجهزة</a>
  <a href="<?= e(url('/games')) ?>" class="<?= is_active_path('/game') ? 'active' : '' ?>"><?= icon('search') ?>تصفح</a>
  <a href="<?= e(url('/account/favorites')) ?>" class="<?= is_active_path('/account/favorites') ? 'active' : '' ?>"><?= icon('heart') ?>المفضلة</a>
  <a href="<?= e(url($u ? '/account' : '/login')) ?>" class="<?= is_active_path('/account') && !is_active_path('/account/favorites') ? 'active' : '' ?>"><?= icon('user') ?><?= $u ? 'حسابي' : 'دخول' ?></a>
</nav>

<?php if (setting('music.enabled', true)): ?>
<div class="dock" data-dock>
  <div class="music-panel glass-strong" data-music-panel hidden>
    <h4>موسيقى الخلفية</h4>
    <small class="muted" data-track-name><?= $track !== '' ? 'مقطوعة مخصصة من الإدارة' : 'Night Shelf — مقطوعة أصلية للمكتبة' ?></small>
    <div class="row">
      <button class="btn btn-sm btn-icon" type="button" data-music-toggle aria-label="تشغيل أو إيقاف الموسيقى"><?= icon('play') ?></button>
      <label class="sr-only" for="vol">مستوى الصوت</label>
      <?= icon('volume') ?><input id="vol" type="range" min="0" max="100" step="1" data-volume>
    </div>
    <label class="switch"><span>مؤثرات الأزرار والتنقل<small>أصوات قصيرة عند الضغط</small></span><input type="checkbox" data-sfx-toggle></label>
  </div>
  <button class="dock-btn" type="button" data-music-btn aria-label="موسيقى الخلفية" aria-expanded="false" data-src="<?= e($musicSrc) ?>">
    <?= icon('music') ?><span class="eq" aria-hidden="true"><i></i><i></i><i></i></span>
  </button>
</div>
<?php endif; ?>

<section class="dl-panel glass-strong" id="dl-panel" hidden aria-label="مدير التنزيلات">
  <div class="head"><?= icon('download') ?><b>مدير التنزيلات</b><button class="icon-btn" type="button" data-dl-hide aria-label="إخفاء"><?= icon('x') ?></button></div>
  <div data-dl-list></div>
</section>

<div class="toasts" id="toasts" aria-live="polite">
  <?php if (is_array($toast)): ?>
    <div class="toast glass-strong <?= e($toast['type'] ?? 'info') ?>" data-auto><?= icon(($toast['type'] ?? '') === 'error' ? 'err' : (($toast['type'] ?? '') === 'success' ? 'ok' : 'info')) ?><span><?= e($toast['message'] ?? '') ?></span><button type="button" aria-label="إغلاق" data-close><?= icon('x') ?></button></div>
  <?php endif; ?>
</div>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<script src="<?= e(asset('js/audio.js')) ?>" defer></script>
<script src="<?= e(asset('js/downloader.js')) ?>" defer></script>
<?php foreach (($extraJs ?? []) as $js): ?><script src="<?= e(asset($js)) ?>" defer></script><?php endforeach; ?>
</body>
</html>
