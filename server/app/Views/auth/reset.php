<div class="wrap auth">
  <div class="auth-card glass sheen">
    <img class="mark" src="<?= e(asset('img/brand/logo.svg')) ?>" alt="" width="56" height="56">
    <h1>كلمة مرور جديدة</h1>
    <p class="sub">بعد الحفظ ستُلغى جلسات التطبيقات المرتبطة بحسابك لحمايته.</p>
    <?php if ($err = field_error('token')): ?><div class="notice bad" style="margin-bottom:14px"><?= icon('alert') ?><span><?= e($err) ?> <a href="<?= e(url('/forgot-password')) ?>">طلب رابط جديد</a></span></div><?php endif; ?>
    <form method="post" action="<?= e(url('/reset-password')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label class="field<?= field_error('password') ? ' has-error' : '' ?>"><span>كلمة المرور الجديدة</span>
        <span class="pw-wrap"><input type="password" name="password" required autocomplete="new-password" minlength="<?= (int) setting('auth.password_min', 8) ?>" data-pw-meter><button class="icon-btn" type="button" data-pw-toggle aria-label="إظهار كلمة المرور"><?= icon('eye') ?></button></span>
        <div class="pw-meter" aria-hidden="true"><i></i></div>
        <?php if ($err = field_error('password')): ?><small class="error"><?= e($err) ?></small><?php endif; ?>
      </label>
      <label class="field"><span>تأكيد كلمة المرور</span><input type="password" name="password_confirmation" required autocomplete="new-password"></label>
      <button class="btn btn-primary btn-block" type="submit"><?= icon('key') ?>حفظ كلمة المرور</button>
    </form>
  </div>
</div>
