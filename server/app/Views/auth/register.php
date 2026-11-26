<div class="wrap auth">
  <div class="auth-card glass sheen">
    <img class="mark" src="<?= e(asset('img/brand/logo.svg')) ?>" alt="" width="56" height="56">
    <h1>إنشاء حساب</h1>
    <p class="sub">احفظ ألعابك المفضلة وتابع تحديثاتها ونزّل الملفات المصرح بها.</p>
    <form method="post" action="<?= e(url('/register')) ?>" novalidate data-register>
      <?= csrf_field() ?>
      <label class="field<?= field_error('username') ? ' has-error' : '' ?>"><span>اسم المستخدم</span>
        <input type="text" name="username" value="<?= e(old('username')) ?>" autocomplete="username" required minlength="3" maxlength="32" pattern="[A-Za-z0-9_.\-]+" dir="ltr">
        <small class="hint">حروف إنجليزية وأرقام و _ . - فقط</small>
        <?php if ($err = field_error('username')): ?><small class="error"><?= e($err) ?></small><?php endif; ?>
      </label>
      <label class="field<?= field_error('email') ? ' has-error' : '' ?>"><span>البريد الإلكتروني</span>
        <input type="email" name="email" value="<?= e(old('email')) ?>" autocomplete="email" required maxlength="190" dir="ltr">
        <?php if ($err = field_error('email')): ?><small class="error"><?= e($err) ?></small><?php endif; ?>
      </label>
      <label class="field<?= field_error('password') ? ' has-error' : '' ?>"><span>كلمة المرور</span>
        <span class="pw-wrap"><input type="password" name="password" autocomplete="new-password" required minlength="<?= (int) setting('auth.password_min', 8) ?>" maxlength="200" data-pw-meter><button class="icon-btn" type="button" data-pw-toggle aria-label="إظهار كلمة المرور"><?= icon('eye') ?></button></span>
        <div class="pw-meter" aria-hidden="true"><i></i></div>
        <small class="hint"><?= (int) setting('auth.password_min', 8) ?> أحرف على الأقل، تجمع بين الحروف والأرقام</small>
        <?php if ($err = field_error('password')): ?><small class="error"><?= e($err) ?></small><?php endif; ?>
      </label>
      <label class="field"><span>تأكيد كلمة المرور</span><input type="password" name="password_confirmation" autocomplete="new-password" required maxlength="200"></label>
      <button class="btn btn-primary btn-block" type="submit" style="margin-top:6px"><?= icon('user-plus') ?>إنشاء الحساب</button>
    </form>
    <p class="auth-foot">لديك حساب؟ <a href="<?= e(url('/login')) ?>">سجّل الدخول</a></p>
  </div>
</div>
