<div class="wrap">
  <?= App\Core\View::file('account/header', []) ?>
  <h2>الأجهزة والتطبيقات المتصلة</h2>
  <p class="muted">كل تطبيق (Android أو PS4) يسجّل دخوله يحصل على رمز وصول خاص. ألغِ أي جهاز لا تعرفه.</p>
  <?php if (!$tokens): ?>
    <div class="glass"><?= partial('empty', ['icon' => 'phone', 'heading' => 'لا توجد أجهزة متصلة', 'text' => 'عند تسجيل الدخول من تطبيق الجوال أو تطبيق PS4 سيظهر هنا.']) ?></div>
  <?php else: ?>
  <div class="glass table-wrap">
    <table class="table">
      <thead><tr><th>الجهاز</th><th>آخر استخدام</th><th>العنوان</th><th>ينتهي في</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($tokens as $t): ?>
        <tr><td><?= icon('phone') ?> <?= e($t['name']) ?></td><td><?= e(time_ago($t['last_used_at'])) ?></td><td class="mono"><?= e($t['last_used_ip'] ?? '—') ?></td><td><?= e(fmt_date($t['expires_at'])) ?></td>
          <td><form method="post" action="<?= e(url('/account/devices/' . (int) $t['id'] . '/revoke')) ?>" data-confirm="إلغاء ربط هذا الجهاز؟"><?= csrf_field() ?><button class="btn btn-xs btn-danger" type="submit"><?= icon('ban') ?>إلغاء الربط</button></form></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
