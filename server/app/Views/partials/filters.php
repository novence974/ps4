<?php
/** @var array $filters @var array $categories @var array $facets */
$f = $filters;
$action = $action ?? url('/games');
$fixed = $fixed ?? [];
$sorts = ['newest' => 'الأحدث إضافة', 'title' => 'ترتيب أبجدي', 'downloads' => 'الأكثر تحميلاً', 'rating' => 'الأعلى تقييماً', 'year' => 'سنة الإصدار', 'updated' => 'آخر تحديث'];
?>
<aside class="filters glass" id="filters" aria-label="خيارات التصفية">
  <form method="get" action="<?= e($action) ?>" data-filter-form>
    <h3><span><?= icon('sliders') ?> التصفية</span><button class="icon-btn filter-toggle" type="button" data-filters-close aria-label="إغلاق"><?= icon('x') ?></button></h3>
    <?php if (!in_array('q', $fixed, true)): ?>
      <label class="field"><span>اسم اللعبة</span><input type="search" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="مثال: Nebula" maxlength="100"></label>
    <?php endif; ?>
    <?php if (!in_array('platform', $fixed, true) && !empty($platforms)): ?>
      <label class="field"><span>الجهاز</span>
        <select name="platform"><option value="">كل الأجهزة</option>
          <?php foreach ($platforms as $p): ?><option value="<?= e($p['slug']) ?>" <?= ($f['platform'] ?? '') === $p['slug'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select></label>
    <?php endif; ?>
    <?php if (!in_array('category', $fixed, true)): ?>
      <label class="field"><span>التصنيف</span>
        <select name="category"><option value="">كل التصنيفات</option>
          <?php foreach ($categories as $c): ?><option value="<?= e($c['slug']) ?>" <?= ($f['category'] ?? '') === $c['slug'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select></label>
    <?php endif; ?>
    <label class="field"><span>سنة الإصدار</span>
      <select name="year"><option value="">كل السنوات</option>
        <?php foreach ($facets['years'] as $y): ?><option value="<?= $y ?>" <?= (int) ($f['year'] ?? 0) === $y ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
      </select></label>
    <label class="field"><span>الشركة المطورة أو الناشرة</span>
      <input type="text" name="company" list="companies" value="<?= e($f['company'] ?? '') ?>" placeholder="اكتب أول حروف الاسم" maxlength="100">
      <datalist id="companies"><?php foreach ($facets['companies'] as $c): ?><option value="<?= e($c) ?>"></option><?php endforeach; ?></datalist>
    </label>
    <label class="field"><span>اللغة</span>
      <select name="language"><option value="">كل اللغات</option>
        <?php foreach (lang_names() as $code => $name): ?><option value="<?= e($code) ?>" <?= ($f['language'] ?? '') === $code ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
      </select></label>
    <label class="field"><span>المنطقة</span>
      <select name="region"><option value="">كل المناطق</option>
        <?php foreach (region_names() as $code => $name): ?><option value="<?= e($code) ?>" <?= ($f['region'] ?? '') === $code ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
      </select></label>
    <div class="field"><span>الحجم (GB)</span>
      <div class="two">
        <input type="number" name="size_min" min="0" step="0.1" value="<?= e($f['size_min'] ?? '') ?>" placeholder="من" aria-label="الحجم الأدنى">
        <input type="number" name="size_max" min="0" step="0.1" value="<?= e($f['size_max'] ?? '') ?>" placeholder="إلى" aria-label="الحجم الأقصى">
      </div>
    </div>
    <label class="field"><span>التقييم الأدنى</span>
      <select name="rating_min"><option value="">أي تقييم</option>
        <?php foreach ([9, 8, 7, 6, 5] as $r): ?><option value="<?= $r ?>" <?= (float) ($f['rating_min'] ?? 0) === (float) $r ? 'selected' : '' ?>><?= $r ?> فأعلى</option><?php endforeach; ?>
      </select></label>
    <label class="field"><span>أضيفت خلال</span>
      <select name="added_within"><option value="">أي وقت</option>
        <?php foreach ([7 => 'آخر أسبوع', 30 => 'آخر شهر', 90 => 'آخر 3 أشهر', 365 => 'آخر سنة'] as $d => $l): ?><option value="<?= $d ?>" <?= (int) ($f['added_within'] ?? 0) === $d ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select></label>
    <label class="field"><span>توفر الملفات</span>
      <select name="availability"><option value="">الكل</option>
        <?php foreach (files_status_names() as $k => $l): ?><option value="<?= e($k) ?>" <?= ($f['availability'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select></label>
    <input type="hidden" name="sort" value="<?= e($f['sort'] ?? '') ?>">
    <div class="form-actions">
      <button class="btn btn-primary btn-block" type="submit"><?= icon('search') ?>عرض النتائج</button>
      <a class="btn btn-ghost btn-block" href="<?= e($action) ?>">مسح كل الفلاتر</a>
    </div>
  </form>
</aside>
