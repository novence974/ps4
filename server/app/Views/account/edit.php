<?php $u = auth_user(); ?>
<div class="wrap">
  <?= App\Core\View::file('account/header', []) ?>
  <div class="grid-2">
    <section class="glass panel">
      <h2><?= icon('user') ?> الملف الشخصي</h2>
      <form method="post" action="<?= e(url('/account/profile')) ?>">
        <?= csrf_field() ?>
        <div class="stack">
          <label class="field"><span>اسم المستخدم</span><input type="text" value="<?= e($u['username']) ?>" disabled dir="ltr"><small class="hint">لا يمكن تغيير اسم المستخدم</small></label>
          <label class="field<?= field_error('display_name') ? ' has-error' : '' ?>"><span>الاسم الظاهر</span><input type="text" name="display_name" value="<?= e(old('display_name', $u['display_name'])) ?>" maxlength="64"><?php if ($e = field_error('display_name')): ?><small class="error"><?= e($e) ?></small><?php endif; ?></label>
          <label class="field<?= field_error('email') ? ' has-error' : '' ?>"><span>البريد الإلكتروني</span><input type="email" name="email" value="<?= e(old('email', $u['email'])) ?>" required dir="ltr" maxlength="190"><small class="hint">تغيير البريد يتطلب تأكيده من جديد</small><?php if ($e = field_error('email')): ?><small class="error"><?= e($e) ?></small><?php endif; ?></label>
          <label class="field"><span>نبذة</span><textarea name="bio" maxlength="500" rows="3"><?= e(old('bio', $u['bio'])) ?></textarea></label>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('save') ?>حفظ الملف الشخصي</button></div>
      </form>
    </section>
    <div class="stack">
      <section class="glass panel">
        <h2><?= icon('image') ?> الصورة الشخصية</h2>
        <div class="row">
          <img class="avatar" src="<?= e(avatar_url($u)) ?>" alt="" style="width:72px;height:72px">
          <form method="post" action="<?= e(url('/account/avatar')) ?>" enctype="multipart/form-data" style="flex:1">
            <?= csrf_field() ?>
            <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" required>
            <div class="form-actions" style="margin-top:10px"><button class="btn btn-sm btn-primary" type="submit"><?= icon('upload') ?>رفع الصورة</button></div>
          </form>
        </div>
        <?php if ($u['avatar_path']): ?><form method="post" action="<?= e(url('/account/avatar/remove')) ?>"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" type="submit"><?= icon('trash') ?>إزالة الصورة</button></form><?php endif; ?>
      </section>
      <section class="glass panel">
        <h2><?= icon('key') ?> كلمة المرور</h2>
        <form method="post" action="<?= e(url('/account/password')) ?>">
          <?= csrf_field() ?>
          <div class="stack">
            <label class="field<?= field_error('current_password') ? ' has-error' : '' ?>"><span>كلمة المرور الحالية</span><input type="password" name="current_password" required autocomplete="current-password"><?php if ($e = field_error('current_password')): ?><small class="error"><?= e($e) ?></small><?php endif; ?></label>
            <label class="field<?= field_error('password') ? ' has-error' : '' ?>"><span>كلمة المرور الجديدة</span><input type="password" name="password" required autocomplete="new-password" minlength="<?= (int) setting('auth.password_min', 8) ?>" data-pw-meter><div class="pw-meter"><i></i></div><?php if ($e = field_error('password')): ?><small class="error"><?= e($e) ?></small><?php endif; ?></label>
            <label class="field"><span>تأكيد كلمة المرور الجديدة</span><input type="password" name="password_confirmation" required autocomplete="new-password"></label>
          </div>
          <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('save') ?>تغيير كلمة المرور</button></div>
        </form>
      </section>
      <section class="glass panel">
        <h2><?= icon('sliders') ?> تفضيلات العرض والصوت</h2>
        <form data-prefs>
          <label class="field"><span>المظهر</span>
            <select name="theme" data-pref="theme">
              <?php foreach (['dark' => 'داكن', 'light' => 'فاتح', 'auto' => 'حسب إعدادات الجهاز'] as $k => $l): ?><option value="<?= $k ?>" <?= ($prefs['theme'] ?? setting('site.default_theme')) === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
            </select></label>
          <label class="switch"><span>تشغيل موسيقى الخلفية تلقائياً بعد أول نقرة<small>لا تبدأ الموسيقى قبل تفاعلك مع الصفحة</small></span><input type="checkbox" name="music" data-pref="music" <?= !empty($prefs['music']) ? 'checked' : '' ?>></label>
          <label class="switch"><span>مؤثرات الأزرار<small>أصوات قصيرة عند الضغط والتنقل</small></span><input type="checkbox" name="sfx" data-pref="sfx" <?= ($prefs['sfx'] ?? true) ? 'checked' : '' ?>></label>
          <p class="muted" style="font-size:var(--fs-xs); margin:6px 0 0">تحفظ التفضيلات تلقائياً في حسابك وعلى هذا الجهاز.</p>
        </form>
      </section>
    </div>
  </div>
</div>
