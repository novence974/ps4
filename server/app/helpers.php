<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\Settings;

function config(string $key, mixed $default = null): mixed { return Config::get($key, $default); }
function setting(string $key, mixed $default = null): mixed { return Settings::get($key, $default); }
function e(mixed $v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'); }
function url(string $path = ''): string { return Request::basePath() . '/' . ltrim($path, '/'); }
function abs_url(string $path = ''): string { return rtrim((string) config('app.url'), '/') . '/' . ltrim($path, '/'); }
function now(): string { return gmdate('Y-m-d H:i:s'); }
function nonce(): string { return App::nonce(); }
function csrf_token(): string { return Csrf::token(); }
function csrf_field(): string { return Csrf::field(); }
function auth_user(): ?array { return Auth::user(); }
function can(string $perm): bool { return Auth::can($perm); }
function abort(int $status, string $message = '', array $headers = []): never { throw new HttpException($status, $message, $headers); }

function asset(string $path): string
{
    $file = PUBLIC_PATH . '/assets/' . $path;
    $v = is_file($file) ? (string) filemtime($file) : APP_VERSION;
    $cdn = (string) setting('site.cdn_url', '');
    return ($cdn !== '' ? rtrim($cdn, '/') . '/assets/' : url('assets/')) . $path . '?v=' . $v;
}

/** Media base path -> URL of a variant, e.g. media_url('covers/2026/11/ab12', 'md', 'webp'). */
function media_url(?string $base, string $variant = 'md', string $ext = 'webp', bool $absolute = false): ?string
{
    if (!$base) {
        return null;
    }
    if (preg_match('#^https?://#', $base)) {
        return $base;
    }
    $rel = 'media/' . ltrim($base, '/') . ($variant !== '' ? '-' . $variant : '') . '.' . $ext;
    $cdn = (string) setting('site.cdn_url', '');
    if ($cdn !== '') {
        return rtrim($cdn, '/') . '/' . $rel;
    }
    return $absolute ? abs_url($rel) : url($rel);
}

function view(string $template, array $data = [], ?string $layout = 'layouts/app', int $status = 200): Response
{
    return Response::html(View::render($template, $data, $layout), $status);
}

function partial(string $name, array $data = []): string
{
    return View::file('partials/' . $name, $data);
}

function redirect(string $to): Response
{
    return Response::redirect(preg_match('#^https?://#', $to) ? $to : url($to));
}

function back(): Response
{
    return Response::redirect(App::backUrl(Request::$current));
}

function flash(string $type, string $message): void
{
    Session::flash('toast', ['type' => $type, 'message' => $message]);
}

function old(string $key, mixed $default = ''): mixed
{
    $old = Session::getFlash('old', []);
    return $old[$key] ?? $default;
}

function field_error(string $key): ?string
{
    $errors = Session::getFlash('errors', []);
    return $errors[$key] ?? null;
}

function json_ok(mixed $data = null, array $meta = [], int $status = 200): Response
{
    $payload = ['ok' => true, 'data' => $data];
    if ($meta) {
        $payload['meta'] = $meta;
    }
    return Response::json($payload, $status);
}

function json_error(string $code, string $message, int $status = 400, array $fields = []): Response
{
    $err = ['code' => $code, 'message' => $message];
    if ($fields) {
        $err['fields'] = $fields;
    }
    return Response::json(['ok' => false, 'error' => $err], $status);
}

function app_tz(): DateTimeZone
{
    static $tz = null;
    try {
        return $tz ??= new DateTimeZone((string) config('app.timezone', 'UTC'));
    } catch (Throwable) {
        return $tz = new DateTimeZone('UTC');
    }
}

function fmt_date(?string $utc, string $format = 'Y/m/d'): string
{
    if (!$utc) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(app_tz())->format($format);
    } catch (Throwable) {
        return '—';
    }
}

function iso_date(?string $utc): ?string
{
    if (!$utc) {
        return null;
    }
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format(DATE_ATOM);
    } catch (Throwable) {
        return null;
    }
}

function time_ago(?string $utc): string
{
    if (!$utc) {
        return '—';
    }
    $diff = time() - (int) strtotime($utc . ' UTC');
    if ($diff < 60) {
        return 'الآن';
    }
    $units = [
        [31536000, 'سنة', 'سنتين', 'سنوات'], [2592000, 'شهر', 'شهرين', 'أشهر'], [604800, 'أسبوع', 'أسبوعين', 'أسابيع'],
        [86400, 'يوم', 'يومين', 'أيام'], [3600, 'ساعة', 'ساعتين', 'ساعات'], [60, 'دقيقة', 'دقيقتين', 'دقائق'],
    ];
    foreach ($units as [$sec, $one, $two, $many]) {
        if ($diff >= $sec) {
            $n = intdiv($diff, $sec);
            return 'منذ ' . match (true) { $n === 1 => $one, $n === 2 => $two, $n <= 10 => $n . ' ' . $many, default => $n . ' ' . $one };
        }
    }
    return 'الآن';
}

/** Noun after a numeral in Arabic: 3–10 take the plural ("5 ألعاب"), other counts the singular ("1 لعبة"، "11 لعبة"). */
function ar_noun(int $n, string $singular, string $plural): string
{
    $m = abs($n) % 100;
    return ($m >= 3 && $m <= 10) ? $plural : $singular;
}

function human_bytes(int|float|null $bytes, int $decimals = 1): string
{
    if ($bytes === null) {
        return '—';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    $b = (float) $bytes;
    while ($b >= 1024 && $i < count($units) - 1) {
        $b /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) (int) $b : number_format($b, $decimals)) . ' ' . $units[$i];
}

function icon(string $name, string $class = ''): string
{
    return '<svg class="icon ' . e($class) . '" aria-hidden="true"><use href="' . e(asset('img/icons.svg')) . '#' . e($name) . '"></use></svg>';
}

function lang_names(): array
{
    return [
        'ar' => 'العربية', 'en' => 'الإنجليزية', 'fr' => 'الفرنسية', 'de' => 'الألمانية', 'es' => 'الإسبانية',
        'it' => 'الإيطالية', 'pt' => 'البرتغالية', 'ru' => 'الروسية', 'ja' => 'اليابانية', 'ko' => 'الكورية',
        'zh' => 'الصينية', 'tr' => 'التركية', 'pl' => 'البولندية', 'nl' => 'الهولندية',
    ];
}

function lang_name(string $code): string { return lang_names()[$code] ?? strtoupper($code); }

function region_names(): array
{
    return ['GLOBAL' => 'عالمي', 'EUR' => 'أوروبا', 'USA' => 'أمريكا', 'JPN' => 'اليابان', 'ASIA' => 'آسيا', 'MENA' => 'الشرق الأوسط'];
}

function region_name(?string $code): string { return $code ? (region_names()[$code] ?? $code) : '—'; }

function file_type_names(): array
{
    return ['game' => 'ملف اللعبة', 'update' => 'تحديث', 'dlc' => 'محتوى إضافي', 'data' => 'ملف بيانات', 'save' => 'ملف حفظ', 'other' => 'ملف آخر'];
}

function access_names(): array
{
    return ['public' => 'للجميع', 'registered' => 'المسجلون', 'verified' => 'الحسابات المؤكدة', 'moderator' => 'المشرفون فأعلى', 'admin' => 'المدراء فقط'];
}

function compat_names(): array
{
    return ['perfect' => 'مثالي', 'playable' => 'قابل للعب', 'ingame' => 'يعمل مع مشاكل', 'menus' => 'القوائم فقط', 'boots' => 'يقلع فقط', 'nothing' => 'لا يعمل', 'unknown' => 'غير مختبر'];
}

function link_type_names(): array
{
    return ['official_store' => 'المتجر', 'publisher' => 'موقع الناشر', 'authorized_mirror' => 'رابط تنزيل', 'info' => 'معلومات'];
}

function update_type_names(): array
{
    return ['game_added' => 'لعبة جديدة', 'game_updated' => 'تحديث لعبة', 'patch' => 'إصدار/تصحيح', 'announcement' => 'إعلان', 'maintenance' => 'صيانة'];
}

function files_status_names(): array
{
    return ['available' => 'الملفات متوفرة', 'unavailable' => 'غير متوفرة حالياً', 'coming_soon' => 'قريباً'];
}

function rating_html(?float $rating): string
{
    if ($rating === null) {
        return '<span class="muted">بدون تقييم</span>';
    }
    $stars = $rating / 2;
    $out = '<span class="stars" aria-label="التقييم ' . e(number_format($rating, 1)) . ' من 10">';
    for ($i = 1; $i <= 5; $i++) {
        $cls = $stars >= $i ? 'full' : ($stars >= $i - 0.5 ? 'half' : 'off');
        $out .= '<i class="star ' . $cls . '"></i>';
    }
    return $out . '<b>' . e(number_format($rating, 1)) . '</b></span>';
}

function avatar_url(?array $u, string $ext = 'webp'): string
{
    if ($u && !empty($u['avatar_path'])) {
        return (string) media_url($u['avatar_path'], 'sm', $ext);
    }
    return asset('img/brand/avatar.svg');
}

function user_label(?array $u): string
{
    return $u ? (string) ($u['display_name'] ?: $u['username']) : 'زائر';
}

/** Telegram link for the owner's username (setting site.telegram, with or without @); null when unset or invalid. */
function telegram_url(): ?string
{
    $u = ltrim(trim((string) setting('site.telegram')), '@');
    if (str_starts_with($u, 'https://t.me/')) {
        $u = substr($u, 13);
    }
    return preg_match('/^[A-Za-z0-9_]{4,32}$/', $u) ? 'https://t.me/' . $u : null;
}

function cover_url(?array $g, string $size = 'md', string $ext = 'webp'): string
{
    return ($g && !empty($g['cover_path'])) ? (string) media_url($g['cover_path'], $size, $ext) : asset('img/brand/cover-placeholder.svg');
}

function platform_logo(?array $p, string $ext = 'webp'): string
{
    return ($p && !empty($p['logo_path'])) ? (string) media_url($p['logo_path'], 'md', $ext) : asset('img/brand/logo.svg');
}

function query_with(array $changes): string
{
    $q = array_merge(Request::$current?->query ?? [], $changes);
    $q = array_filter($q, static fn ($v) => $v !== null && $v !== '');
    return $q ? '?' . http_build_query($q) : '?';
}

function is_active_path(string $prefix): bool
{
    $p = Request::$current?->path() ?? '/';
    return $prefix === '/' ? $p === '/' : str_starts_with($p, $prefix);
}
