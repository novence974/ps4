<div class="wrap">
  <h1 class="page-title">التصنيفات</h1>
  <p class="page-sub">اختر نوع اللعب الذي تبحث عنه، ويمكنك بعد ذلك التصفية حسب الجهاز والسنة واللغة.</p>
  <div class="cat-grid" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr))">
    <?php foreach ($categories as $c): ?>
      <a class="cat" href="<?= e(url('category/' . $c['slug'])) ?>" style="--cc: <?= e($c['color']) ?>; padding:20px">
        <span class="ico" style="width:52px;height:52px"><?= icon('cat-' . ($c['icon'] ?: 'bolt')) ?></span>
        <span><b><?= e($c['name']) ?></b><small><?= e($c['name_en']) ?> — <?= number_format((int) $c['games_count']) ?> <?= ar_noun((int) $c['games_count'], 'لعبة', 'ألعاب') ?></small></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
