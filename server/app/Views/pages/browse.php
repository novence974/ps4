<div class="wrap">
  <?php if ($category): ?>
    <div class="row" style="margin-top:32px; gap:16px">
      <span class="cat" style="--cc: <?= e($category['color']) ?>; padding:0; border:0; background:none"><span class="ico" style="width:56px;height:56px"><?= icon('cat-' . ($category['icon'] ?: 'bolt')) ?></span></span>
      <div><h1 style="margin:0"><?= e($category['name']) ?></h1><span class="muted"><?= number_format((int) $category['games_count']) ?> <?= ar_noun((int) $category['games_count'], 'لعبة منشورة', 'ألعاب منشورة') ?> في هذا التصنيف</span></div>
    </div>
  <?php else: ?>
    <h1 class="page-title"><?= !empty($filters['q']) ? 'نتائج البحث عن «' . e($filters['q']) . '»' : 'تصفح المكتبة' ?></h1>
  <?php endif; ?>
  <div class="browse">
    <?= partial('filters', ['filters' => $filters, 'platforms' => $platforms, 'categories' => $categories, 'facets' => $facets,
        'fixed' => $category ? ['category'] : [], 'action' => $category ? url('category/' . $category['slug']) : url('/games')]) ?>
    <?= partial('results', ['result' => $result, 'filters' => $filters, 'favIds' => $favIds, 'fixed' => $category ? ['category'] : []]) ?>
  </div>
</div>
