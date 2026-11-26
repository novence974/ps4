<div class="wrap error-page">
  <div class="code"><?= (int) $status ?></div>
  <h1><?= e($message) ?></h1>
  <p class="muted">
    <?php if ($status === 404): ?>ربما نُقلت الصفحة أو أُخفيت اللعبة. جرّب البحث أو العودة للرئيسية.<?php elseif ($status === 419): ?>أعد تحميل الصفحة ثم أرسل النموذج مرة أخرى.<?php elseif ($status === 403): ?>إن كنت تعتقد أن هذا خطأ، تواصل مع مدير المكتبة.<?php endif; ?>
  </p>
  <div class="row" style="justify-content:center; margin-top:20px">
    <a class="btn btn-primary" href="<?= e(url('/')) ?>"><?= icon('home') ?>الرئيسية</a>
    <a class="btn" href="<?= e(url('/games')) ?>"><?= icon('search') ?>تصفح المكتبة</a>
  </div>
  <?php if (!empty($debug)): ?><pre class="glass"><?= e($debug) ?></pre><?php endif; ?>
</div>
