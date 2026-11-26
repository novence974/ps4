<div class="wrap auth">
  <div class="auth-card glass sheen">
    <img class="mark" src="<?= e(asset('img/brand/logo.svg')) ?>" alt="" width="56" height="56">
    <h1>تسجيل الدخول</h1>
    <p class="sub">ادخل إلى مكتبتك ومفضلتك وسجل تنزيلاتك.</p>
    <form method="post" action="<?= e(url('/login')) ?>" novalidate>
      <?= csrf_field() ?>
      <label class="field<?= field_error('login') ? ' has-error' : '' ?>"><span>اسم المستخدم أو البريد الإلكتروني</span>
        <input type="text" name="login" value="<?= e(old('login')) ?>" autocomplete="username" required autofocus maxlength="190">
        <?php if ($err = field_error('login')): ?><small class="error"><?= e($err) ?></small><?php endif; ?>
      </label>
      <label class="field"><span>كلمة المرور</span>
        <span class="pw-wrap"><input type="password" name="password" autocomplete="current-password" required maxlength="200"><button class="icon-btn" type="button" data-pw-toggle aria-label="إظهار كلمة المرور"><?= icon('eye') ?></button></span>
      </label>
      <div class="row" style="justify-content:space-between; margin:4px 0 18px">
        <span></span><a class="muted" href="<?= e(url('/forgot-password')) ?>" style="font-size:var(--fs-sm)">نسيت كلمة المرور؟</a>
      </div>
      <button class="btn btn-primary btn-block" type="submit"><?= icon('login') ?>دخول</button>
    </form>
    <?php if (setting('auth.registration_open', true)): ?><p class="auth-foot">ليس لديك حساب؟ <a href="<?= e(url('/register')) ?>">أنشئ حساباً مجانياً</a></p><?php endif; ?>
  </div>
</div>
