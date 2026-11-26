<div class="wrap auth">
  <div class="auth-card glass sheen">
    <img class="mark" src="<?= e(asset('img/brand/logo.svg')) ?>" alt="" width="56" height="56">
    <h1>استعادة كلمة المرور</h1>
    <p class="sub">اكتب بريدك المسجل وسنرسل لك رابطاً لتعيين كلمة مرور جديدة، صالحاً لمدة ساعة.</p>
    <form method="post" action="<?= e(url('/forgot-password')) ?>">
      <?= csrf_field() ?>
      <label class="field<?= field_error('email') ? ' has-error' : '' ?>"><span>البريد الإلكتروني</span>
        <input type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email" dir="ltr" maxlength="190">
        <?php if ($err = field_error('email')): ?><small class="error"><?= e($err) ?></small><?php endif; ?>
      </label>
      <button class="btn btn-primary btn-block" type="submit"><?= icon('send') ?>إرسال رابط الاستعادة</button>
    </form>
    <p class="auth-foot"><a href="<?= e(url('/login')) ?>">العودة لتسجيل الدخول</a></p>
  </div>
</div>
