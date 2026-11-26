<div class="wrap">
  <?= App\Core\View::file('account/header', []) ?>
  <div class="section-head">
    <h2><?= e($title) ?> <span class="muted" style="font-size:var(--fs-sm)">(<?= (int) $result['total'] ?>)</span></h2>
    <?php if ($kind === 'history' && $result['total']): ?>
      <form method="post" action="<?= e(url('/account/history/clear')) ?>" data-confirm="مسح سجل المشاهدة بالكامل؟"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= icon('trash') ?>مسح السجل</button></form>
    <?php endif; ?>
  </div>
  <?php if (!$result['items']): ?>
    <div class="glass"><?= partial('empty', $kind === 'favorites'
        ? ['icon' => 'heart', 'heading' => 'قائمة المفضلة فارغة', 'text' => 'أضف الألعاب التي تهمك لتصلك إشعارات إصداراتها الجديدة.', 'action' => ['/games', 'تصفح المكتبة']]
        : ['icon' => 'history', 'heading' => 'سجل المشاهدة فارغ', 'text' => 'الألعاب التي تفتح صفحاتها تظهر هنا.', 'action' => ['/games', 'تصفح المكتبة']]) ?></div>
  <?php else: ?>
    <div class="cards"><?= partial('game-grid', ['games' => $result['items'], 'favIds' => $favIds]) ?></div>
    <?= partial('pagination', ['result' => $result]) ?>
  <?php endif; ?>
</div>
