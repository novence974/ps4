<?php
/** @var array $result @var array $filters */
$f = $filters;
$sorts = ['newest' => 'الأحدث إضافة', 'title' => 'ترتيب أبجدي', 'downloads' => 'الأكثر تحميلاً', 'rating' => 'الأعلى تقييماً', 'year' => 'سنة الإصدار', 'updated' => 'آخر تحديث'];
if (!empty($f['q'])) { $sorts = ['relevance' => 'الأقرب للبحث'] + $sorts; }
$labels = ['q' => 'بحث', 'platform' => 'الجهاز', 'category' => 'التصنيف', 'year' => 'السنة', 'company' => 'الشركة', 'language' => 'اللغة', 'region' => 'المنطقة',
    'size_min' => 'الحجم من', 'size_max' => 'الحجم إلى', 'rating_min' => 'التقييم', 'added_within' => 'أضيفت خلال (يوم)', 'availability' => 'التوفر'];
$fixed = $fixed ?? [];
?>
<div>
  <div class="results-bar">
    <button class="btn btn-sm filter-toggle" type="button" data-filters-open aria-controls="filters"><?= icon('sliders') ?>التصفية</button>
    <span class="count"><b><?= number_format((int) $result['total']) ?></b> <?= ar_noun((int) $result['total'], 'لعبة', 'ألعاب') ?></span>
    <span class="spacer"></span>
    <form method="get" class="row" data-sort-form>
      <?php foreach ($f as $k => $v): if ($k === 'sort' || $k === 'order') { continue; } ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
      <label class="sr-only" for="sort">ترتيب حسب</label>
      <select id="sort" name="sort" data-autosubmit>
        <?php foreach ($sorts as $k => $l): ?><option value="<?= e($k) ?>" <?= ($f['sort'] ?? (empty($f['q']) ? 'newest' : 'relevance')) === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-sm" type="submit">ترتيب</button></noscript>
    </form>
  </div>
  <?php $active = array_diff_key($f, array_flip(array_merge(['sort', 'order'], $fixed))); if ($active): ?>
    <div class="active-filters">
      <?php foreach ($active as $k => $v): ?>
        <a class="chip" href="<?= e(query_with([$k => null, 'page' => null])) ?>" aria-label="إزالة الفلتر <?= e($labels[$k] ?? $k) ?>"><?= e(($labels[$k] ?? $k) . ': ' . ($k === 'language' ? lang_name((string) $v) : ($k === 'region' ? region_name((string) $v) : $v))) ?> <?= icon('x') ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if (!$result['items']): ?>
    <div class="glass"><?= partial('empty', ['icon' => 'search', 'heading' => 'لا توجد ألعاب مطابقة', 'text' => 'جرّب إزالة بعض الفلاتر أو البحث بكلمة أقصر.']) ?></div>
  <?php else: ?>
    <div class="cards" data-grid><?= partial('game-grid', ['games' => $result['items'], 'favIds' => $favIds ?? []]) ?></div>
    <?php if ($result['page'] < $result['pages']): ?>
      <div class="load-more"><button class="btn" type="button" data-load-more data-next="<?= (int) $result['page'] + 1 ?>" data-pages="<?= (int) $result['pages'] ?>"><?= icon('plus') ?>عرض المزيد</button></div>
    <?php endif; ?>
    <?= partial('pagination', ['result' => $result]) ?>
  <?php endif; ?>
</div>
