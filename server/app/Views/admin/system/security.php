<?php $h = $health; ?>
<form method="post" action="<?= e(url('/admin/security')) ?>"><?= csrf_field() ?>
<div class="dash">
  <div>
    <section class="glass card"><h2><?= icon('lock') ?>الحسابات وتسجيل الدخول</h2>
      <input type="hidden" name="auth_registration_open" value="0"><label class="switch"><span>التسجيل مفتوح للزوار</span><input type="checkbox" name="auth_registration_open" value="1" <?= setting('auth.registration_open', true) ? 'checked' : '' ?>></label>
      <input type="hidden" name="auth_email_verification" value="0"><label class="switch"><span>طلب تأكيد البريد الإلكتروني<small>الملفات ذات صلاحية «الحسابات المؤكدة» تتطلب التأكيد</small></span><input type="checkbox" name="auth_email_verification" value="1" <?= setting('auth.email_verification', true) ? 'checked' : '' ?>></label>
      <div class="form-grid" style="margin-top:8px">
        <label class="field"><span>أقل طول لكلمة المرور</span><input type="number" name="auth_password_min" value="<?= (int) setting('auth.password_min', 8) ?>" min="8" max="128"></label>
        <label class="field"><span>محاولات فاشلة قبل القفل</span><input type="number" name="auth_max_attempts" value="<?= (int) setting('auth.max_attempts', 5) ?>" min="3" max="50"></label>
        <label class="field"><span>مدة القفل (دقيقة)</span><input type="number" name="auth_lockout_minutes" value="<?= (int) setting('auth.lockout_minutes', 15) ?>" min="1" max="1440"></label>
        <label class="field"><span>انتهاء الجلسة بعد خمول (دقيقة)</span><input type="number" name="auth_session_idle_minutes" value="<?= (int) setting('auth.session_idle_minutes', 120) ?>" min="5" max="10080"></label>
      </div>
    </section>
    <section class="glass card"><h2><?= icon('cloud-upload') ?>الرفع والتنزيل</h2>
      <div class="form-grid">
        <label class="field full"><span>امتدادات ملفات الألعاب المسموحة</span><input type="text" name="uploads_allowed_extensions" value="<?= e(setting('uploads.allowed_extensions')) ?>" dir="ltr"><small class="hint">مفصولة بفواصل. الامتدادات التنفيذية (php, exe, sh, js, svg, html…) ممنوعة دائماً مهما كتبت هنا.</small></label>
        <label class="field"><span>أقصى حجم للملف (GB)</span><input type="number" name="uploads_max_file_gb" value="<?= (int) setting('uploads.max_file_gb', 200) ?>" min="1" max="2048"></label>
        <label class="field"><span>أقصى حجم للصور (MB)</span><input type="number" name="uploads_image_max_mb" value="<?= (int) setting('uploads.image_max_mb', 15) ?>" min="1" max="100"></label>
        <label class="field"><span>حجم الجزء (MB)</span><input type="number" name="uploads_chunk_size_mb" value="<?= (int) setting('uploads.chunk_size_mb', 5) ?>" min="1" max="64"><small class="hint">يُقيَّد تلقائياً بـ post_max_size (<?= e((string) ini_get('post_max_size')) ?>)</small></label>
        <label class="field"><span>التجميع في الخلفية فوق (MB)</span><input type="number" name="uploads_sync_assemble_limit_mb" value="<?= (int) setting('uploads.sync_assemble_limit_mb', 2048) ?>" min="16"></label>
        <label class="field"><span>صلاحية رابط التنزيل (دقيقة)</span><input type="number" name="downloads_link_ttl_minutes" value="<?= (int) setting('downloads.link_ttl_minutes', 360) ?>" min="5" max="10080"></label>
        <label class="field"><span>حد روابط التنزيل لكل مستخدم في الساعة</span><input type="number" name="downloads_max_links_per_hour" value="<?= (int) setting('downloads.max_links_per_hour', 60) ?>" min="1"></label>
        <label class="field"><span>قرص التخزين الافتراضي</span><select name="storage_default_disk"><?php foreach ($disks as $d): ?><option value="<?= e($d) ?>" <?= setting('storage.default_disk', 'local') === $d ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?></select></label>
      </div>
    </section>
    <div class="form-actions" style="margin-bottom:18px"><button class="btn btn-primary" type="submit"><?= icon('save') ?>حفظ إعدادات الأمان</button></div>
  </div>
  <div>
    <section class="glass card"><h2><?= icon('shield') ?>الحمايات المفعّلة</h2>
      <ul class="list" style="font-size:var(--fs-sm)">
        <?php foreach (['تشفير كلمات المرور بـ ' . (defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt') . ' مع إعادة التشفير التلقائي', 'رموز CSRF لكل النماذج وطلبات الجلسة', 'Prepared Statements لكل استعلامات قاعدة البيانات', 'ترويسات CSP وX-Frame-Options وnosniff' . (App\Core\Request::$current?->isSecure() ? ' وHSTS' : ''), 'جلسات HttpOnly + SameSite مع تجديد المعرّف ومهلة خمول', 'رموز API مخزنة كبصمات SHA-256 فقط مع انتهاء صلاحية', 'تحديد معدل الطلبات وقفل الحساب بعد المحاولات الفاشلة', 'إعادة ترميز كل الصور المرفوعة ومنع الامتدادات التنفيذية', 'روابط تنزيل موقّعة HMAC قصيرة العمر مع إعادة فحص الصلاحية', 'الملفات الكبيرة خارج مجلد الويب العام'] as $item): ?>
          <li><?= icon('ok') ?><span><?= e($item) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php if (!App\Core\Request::$current?->isSecure()): ?><div class="notice warn"><?= icon('alert') ?><span>الموقع يعمل دون HTTPS. فعّل شهادة SSL قبل الإطلاق ثم اضبط <span class="code-inline">security.force_https</span> على true.</span></div><?php endif; ?>
    </section>
    <section class="glass card"><h2><?= icon('lock') ?>حسابات مقفلة مؤقتاً</h2>
      <?php if (!$locked): ?><p class="muted">لا توجد حسابات مقفلة.</p><?php else: ?><ul class="list"><?php foreach ($locked as $l): ?><li><div class="grow"><b><?= e($l['username']) ?></b><small>حتى <?= e(fmt_date($l['locked_until'], 'H:i')) ?></small></div><button class="btn btn-xs" type="submit" form="unlock-<?= (int) $l['id'] ?>">فك القفل</button></li><?php endforeach; ?></ul><?php endif; ?>
    </section>
    <section class="glass card"><h2><?= icon('shield-alert') ?>آخر محاولات الدخول الفاشلة</h2>
      <?php if (!$failed): ?><p class="muted">لا توجد.</p><?php else: ?><ul class="list"><?php foreach ($failed as $f): ?><li><span class="tag <?= $f['action'] === 'auth.locked' ? 'tag-bad' : 'tag-warn' ?>"><?= e(App\Services\ActivityLog::label($f['action'])) ?></span><div class="grow"><small><?= e($f['username'] ?? '') ?></small></div><small class="mono"><?= e($f['ip'] ?? '') ?></small><small><?= e(time_ago($f['created_at'])) ?></small></li><?php endforeach; ?></ul><?php endif; ?>
    </section>
    <section class="glass card"><h2><?= icon('cpu') ?>بيئة الخادم</h2>
      <div class="health">
        <div><span>PHP</span><b class="ltr"><?= e($h['php']) ?></b></div>
        <div><span>قاعدة البيانات</span><b class="ltr"><?= e((string) $h['db_version']) ?></b></div>
        <?php foreach ($h['extensions'] as $ext => $ok): ?><div><span class="ltr"><?= e($ext) ?></span><b><?= $ok ? '<span class="tag tag-ok">مفعّل</span>' : '<span class="tag">غير مفعّل</span>' ?></b></div><?php endforeach; ?>
        <?php foreach ($h['limits'] as $k => $val): ?><div><span class="ltr"><?= e($k) ?></span><b class="ltr"><?= e((string) $val) ?></b></div><?php endforeach; ?>
      </div>
    </section>
  </div>
</div>
</form>
<?php foreach ($locked as $l): ?><form id="unlock-<?= (int) $l['id'] ?>" method="post" action="<?= e(url('/admin/security/unlock/' . (int) $l['id'])) ?>" hidden><?= csrf_field() ?></form><?php endforeach; ?>
