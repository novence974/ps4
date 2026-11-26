<?php $m = $mail; ?>
<form method="post" action="<?= e(url('/admin/settings')) ?>"><?= csrf_field() ?>
<div class="dash">
  <div>
    <section class="glass card"><h2><?= icon('settings') ?>الموقع</h2>
      <div class="form-grid">
        <label class="field"><span>اسم المكتبة</span><input type="text" name="site_name" value="<?= e(setting('site.name')) ?>" maxlength="80"></label>
        <label class="field"><span>الوصف المختصر</span><input type="text" name="site_tagline" value="<?= e(setting('site.tagline')) ?>" maxlength="160"></label>
        <label class="field"><span>المظهر الافتراضي</span><select name="site_default_theme"><?php foreach (['dark' => 'داكن', 'light' => 'فاتح', 'auto' => 'حسب الجهاز'] as $k => $l): ?><option value="<?= $k ?>" <?= setting('site.default_theme') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>عدد الألعاب في الصفحة</span><input type="number" name="site_per_page" value="<?= (int) setting('site.per_page', 24) ?>" min="6" max="100"></label>
        <label class="field full"><span>رابط CDN (اختياري)</span><input type="text" name="site_cdn_url" value="<?= e(setting('site.cdn_url')) ?>" dir="ltr" placeholder="https://cdn.example.com"><small class="hint">يُقدَّم منه مجلدا assets و media. اتركه فارغاً لاستخدام الخادم نفسه.</small></label>
      </div>
      <input type="hidden" name="updates_auto_on_publish" value="0"><label class="switch"><span>إنشاء خبر تلقائي عند نشر لعبة جديدة</span><input type="checkbox" name="updates_auto_on_publish" value="1" <?= setting('updates.auto_on_publish', true) ? 'checked' : '' ?>></label>
      <input type="hidden" name="site_maintenance" value="0"><label class="switch"><span>وضع الصيانة<small>يُغلق الموقع والتطبيقات أمام الزوار، ويبقى المدراء قادرين على الدخول</small></span><input type="checkbox" name="site_maintenance" value="1" <?= setting('site.maintenance', false) ? 'checked' : '' ?>></label>
      <label class="field"><span>رسالة الصيانة</span><input type="text" name="site_maintenance_message" value="<?= e(setting('site.maintenance_message')) ?>" maxlength="300"></label>
    </section>
    <section class="glass card"><h2><?= icon('mail') ?>البريد الإلكتروني</h2>
      <div class="form-grid">
        <label class="field"><span>طريقة الإرسال</span><select name="mail_driver"><option value="smtp" <?= $m['driver'] === 'smtp' ? 'selected' : '' ?>>SMTP (موصى به)</option><option value="mail" <?= $m['driver'] === 'mail' ? 'selected' : '' ?>>دالة mail() في PHP</option><option value="log" <?= $m['driver'] === 'log' ? 'selected' : '' ?>>سجل فقط (للتجربة)</option></select></label>
        <label class="field"><span>خادم SMTP</span><input type="text" name="mail_host" value="<?= e($m['host']) ?>" dir="ltr" placeholder="smtp.example.com"></label>
        <label class="field"><span>المنفذ</span><input type="number" name="mail_port" value="<?= (int) $m['port'] ?>" min="1" max="65535"></label>
        <label class="field"><span>التشفير</span><select name="mail_encryption"><?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => 'بدون'] as $k => $l): ?><option value="<?= $k ?>" <?= $m['encryption'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>اسم المستخدم</span><input type="text" name="mail_username" value="<?= e($m['username']) ?>" dir="ltr" autocomplete="off"></label>
        <label class="field"><span>كلمة المرور</span><input type="password" name="mail_password" value="" placeholder="<?= $m['password'] !== '' ? '•••••••• (محفوظة ومشفرة)' : '' ?>" autocomplete="new-password"><small class="hint">اتركها فارغة للإبقاء على الحالية</small></label>
        <label class="field"><span>بريد المرسل</span><input type="email" name="mail_from_address" value="<?= e($m['from_address']) ?>" dir="ltr" required></label>
        <label class="field"><span>اسم المرسل</span><input type="text" name="mail_from_name" value="<?= e($m['from_name']) ?>" maxlength="120"></label>
      </div>
    </section>
    <div class="form-actions" style="margin-bottom:18px"><button class="btn btn-primary" type="submit"><?= icon('save') ?>حفظ الإعدادات</button><button class="btn" type="submit" form="mail-test"><?= icon('send') ?>إرسال رسالة اختبار</button></div>
  </div>
  <div>
    <section class="glass card"><h2><?= icon('music') ?>الموسيقى والمؤثرات</h2>
      <input type="hidden" name="music_enabled" value="0"><label class="switch"><span>إظهار مشغل موسيقى الخلفية</span><input type="checkbox" name="music_enabled" value="1" <?= setting('music.enabled', true) ? 'checked' : '' ?>></label>
      <input type="hidden" name="sfx_enabled" value="0"><label class="switch"><span>مؤثرات الأزرار والتنقل</span><input type="checkbox" name="sfx_enabled" value="1" <?= setting('sfx.enabled', true) ? 'checked' : '' ?>></label>
      <label class="field"><span>مستوى الصوت الافتراضي: <b data-vol-label><?= (int) setting('music.default_volume', 35) ?></b>%</span><input type="range" name="music_default_volume" min="0" max="100" value="<?= (int) setting('music.default_volume', 35) ?>" data-vol-input style="width:100%; accent-color:var(--aqua)"></label>
      <p class="muted" style="font-size:var(--fs-xs)">الموسيقى لا تبدأ أبداً قبل تفاعل الزائر، ويُحفظ اختياره على جهازه وفي حسابه.</p>
      <div class="notice" style="margin-top:6px"><?= icon('music') ?><span style="flex:1">المقطوعة الحالية: <?= setting('music.track') ? '<span class="code-inline">' . e(setting('music.track')) . '</span>' : 'Night Shelf (أصلية ومرخصة مع المشروع)' ?></span></div>
    </section>
  </div>
</div>
</form>
<div class="dash" style="margin-top:0">
  <div></div>
  <section class="glass card"><h2><?= icon('upload') ?>استبدال موسيقى الخلفية</h2>
    <form method="post" action="<?= e(url('/admin/settings/music')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
      <input type="file" name="file" accept=".mp3,.ogg,audio/mpeg,audio/ogg" required>
      <p class="muted" style="font-size:var(--fs-xs)">استخدم موسيقى أصلية أو مرخصة لك فقط (MP3 أو OGG).</p>
      <div class="row"><button class="btn btn-sm btn-primary" type="submit"><?= icon('upload') ?>رفع</button></div></form>
    <?php if (setting('music.track')): ?><form method="post" action="<?= e(url('/admin/settings/music/remove')) ?>" style="margin-top:8px"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" type="submit"><?= icon('undo') ?>العودة للموسيقى الافتراضية</button></form><?php endif; ?>
  </section>
</div>
<form id="mail-test" method="post" action="<?= e(url('/admin/settings/mail-test')) ?>" hidden><?= csrf_field() ?></form>
