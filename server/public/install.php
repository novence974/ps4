<?php
declare(strict_types=1);
/* Web installer — works only while config/config.php does not exist. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\ValidationException;
use App\Services\Installer;

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store');
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if (session_status() === PHP_SESSION_NONE) {
    session_name('UPSL_INSTALL');
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

$installed = Config::installed() || is_file(STORAGE_PATH . '/installed.lock');
$errors = [];
$done = null;
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$guessUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base;
$in = array_merge(['db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => 'upsl', 'db_user' => '', 'app_url' => $guessUrl, 'timezone' => 'Asia/Qatar',
    'site_name' => 'ULTIMATE PS LIBRARY', 'admin_username' => 'admin', 'admin_email' => '', 'demo' => '1'], $_POST);

if (!$installed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors['_'] = 'انتهت صلاحية النموذج، أعد المحاولة';
    } elseif (!Installer::ready()) {
        $errors['_'] = 'المتطلبات غير مكتملة';
    } else {
        @set_time_limit(300);
        try {
            if (($in['admin_password'] ?? '') !== ($in['admin_password_confirmation'] ?? '')) {
                throw ValidationException::single('admin_password', 'تأكيد كلمة المرور غير مطابق');
            }
            $done = Installer::install(['demo' => !empty($_POST['demo'])] + $in); // the checkbox decides (not the form default)
            $installed = true;
        } catch (ValidationException $e) {
            $errors = $e->errors;
        } catch (Throwable $e) {
            $errors['_'] = $e->getMessage();
        }
    }
}
$req = Installer::requirements();
$ready = Installer::ready();
$field = static function (string $name, string $label, string $type = 'text', string $hint = '') use ($in, $errors, $h): string {
    $err = $errors[$name] ?? null;
    $val = $type === 'password' ? '' : ($in[$name] ?? '');
    return '<label><span>' . $h($label) . '</span><input type="' . $type . '" name="' . $name . '" value="' . $h($val) . '"' . ($type !== 'password' || $name !== 'db_pass' ? '' : '') . ' dir="auto">'
        . ($hint ? '<small>' . $h($hint) . '</small>' : '') . ($err ? '<b class="err">' . $h($err) . '</b>' : '') . '</label>';
};
?><!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>تثبيت ULTIMATE PS LIBRARY</title>
<style>
@font-face{font-family:"Readex Pro";src:url("<?= $h($base) ?>/assets/fonts/readex-pro.woff2") format("woff2");font-weight:160 700}
*{box-sizing:border-box}body{margin:0;font-family:"Readex Pro",Tahoma,sans-serif;background:radial-gradient(60% 50% at 80% 0%,rgba(139,124,255,.25),transparent),#070a1c;color:#e8ebff;min-height:100vh;padding:32px 16px}
.box{max-width:880px;margin:0 auto;background:rgba(150,160,255,.07);border:1px solid rgba(176,186,255,.16);border-radius:24px;padding:28px;backdrop-filter:blur(20px)}
h1{margin:0 0 6px;font-size:1.6rem}p{color:#9aa3c7}h2{font-size:1.1rem;margin:24px 0 12px}
.req{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:6px;font-size:.88rem}.req div{padding:6px 10px;border-radius:10px;background:rgba(0,0,0,.2)}
.ok{color:#4ade80}.bad{color:#fb7185}.opt{color:#9aa3c7}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}label{display:block}label span{display:block;font-size:.85rem;color:#9aa3c7;margin-bottom:5px}
input{width:100%;padding:11px 13px;border-radius:12px;border:1px solid rgba(176,186,255,.2);background:rgba(10,14,40,.5);color:#e8ebff;font:inherit}
small{display:block;color:#6b7399;font-size:.75rem;margin-top:4px}.err{display:block;color:#fb7185;font-size:.8rem;margin-top:4px;font-weight:400}
button,.btn{display:inline-block;margin-top:22px;padding:13px 26px;border-radius:999px;border:0;background:linear-gradient(120deg,#8b7cff,#5aa9ff 55%,#5ee6f0);color:#0a0d24;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}
button[disabled]{opacity:.5}.alert{padding:12px 16px;border-radius:12px;background:rgba(251,113,133,.12);border:1px solid rgba(251,113,133,.35);margin:12px 0}
.success{background:rgba(74,222,128,.1);border-color:rgba(74,222,128,.35)}.check{display:flex;gap:8px;align-items:center;margin-top:14px}.check input{width:auto}
code{direction:ltr;unicode-bidi:isolate;background:rgba(150,160,255,.12);padding:2px 6px;border-radius:6px}
@media(max-width:700px){.grid{grid-template-columns:1fr}}
</style></head><body><div class="box">
<h1>تثبيت ULTIMATE PS LIBRARY</h1>
<?php if ($installed && $done): ?>
  <div class="alert success">تم التثبيت بنجاح. <?= $done['demo'] ? 'أضيفت ' . (int) $done['demo']['games'] . ' لعبة تجريبية.' : '' ?></div>
  <p>الخطوات التالية: احذف ملف <code>public/install.php</code> أو اتركه (يتعطل تلقائياً بعد التثبيت)، وأضف مهمة Cron كل دقيقة: <code>php <?= $h(BASE_PATH) ?>/bin/cron.php</code></p>
  <a class="btn" href="<?= $h($base) ?>/login">تسجيل الدخول للوحة التحكم</a>
<?php elseif ($installed): ?>
  <div class="alert">المكتبة مثبتة مسبقاً. لإعادة التثبيت احذف <code>config/config.php</code> و <code>storage/installed.lock</code> واستخدم قاعدة بيانات فارغة.</div>
  <a class="btn" href="<?= $h($base) ?>/">الذهاب للموقع</a>
<?php else: ?>
  <p>يجهّز هذا المعالج قاعدة البيانات وملف الإعدادات وحساب المدير الرئيسي.</p>
  <h2>المتطلبات</h2>
  <div class="req"><?php foreach ($req as [$label, $ok, $detail, $required]): ?><div class="<?= $ok ? 'ok' : ($required ? 'bad' : 'opt') ?>"><?= $ok ? '✓' : ($required ? '✗' : '–') ?> <?= $h($label) ?> <small><?= $h($detail) ?></small></div><?php endforeach; ?></div>
  <?php if (!empty($errors['_'])): ?><div class="alert"><?= $h($errors['_']) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <h2>قاعدة البيانات (MySQL 8.0+ / MariaDB 10.5+)</h2>
    <div class="grid"><?= $field('db_host', 'الخادم') ?><?= $field('db_port', 'المنفذ', 'number') ?><?= $field('db_name', 'اسم قاعدة البيانات', 'text', 'تُنشأ تلقائياً إن كانت لديك الصلاحية') ?><?= $field('db_user', 'اسم المستخدم') ?><?= $field('db_pass', 'كلمة المرور', 'password') ?></div>
    <h2>الموقع</h2>
    <div class="grid"><?= $field('app_url', 'رابط الموقع', 'url', 'يُستخدم في روابط البريد والتطبيقات') ?><?= $field('site_name', 'اسم المكتبة') ?><?= $field('timezone', 'المنطقة الزمنية', 'text', 'مثال: Asia/Qatar أو Asia/Riyadh') ?></div>
    <h2>حساب المدير الرئيسي</h2>
    <div class="grid"><?= $field('admin_username', 'اسم المستخدم') ?><?= $field('admin_email', 'البريد الإلكتروني', 'email') ?><?= $field('admin_password', 'كلمة المرور', 'password', '8 أحرف على الأقل تجمع الحروف والأرقام') ?><?= $field('admin_password_confirmation', 'تأكيد كلمة المرور', 'password') ?></div>
    <?php foreach (['username', 'email', 'password'] as $k): if (!empty($errors[$k])): ?><div class="alert"><?= $h($errors[$k]) ?></div><?php endif; endforeach; ?>
    <label class="check"><input type="checkbox" name="demo" value="1" <?= !empty($in['demo']) ? 'checked' : '' ?>> إضافة 31 لعبة تجريبية خيالية بأغلفة وصور أصلية (يمكن حذفها لاحقاً)</label>
    <button type="submit" <?= $ready ? '' : 'disabled' ?>>تثبيت المكتبة</button>
  </form>
<?php endif; ?>
</div></body></html>
