<?php /** @var array $result @var array $filters */ $f = $filters; ?>
<div class="toolbar">
  <form method="get" action="<?= e(url('/admin/games')) ?>">
    <input type="search" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="اسم، رمز منتج، شركة…" aria-label="بحث">
    <select name="platform" aria-label="الجهاز"><option value="">كل الأجهزة</option><?php foreach ($platforms as $p): ?><option value="<?= e($p['slug']) ?>" <?= ($f['platform'] ?? '') === $p['slug'] ? 'selected' : '' ?>><?= e($p['short_name']) ?></option><?php endforeach; ?></select>
    <select name="category" aria-label="التصنيف"><option value="">كل التصنيفات</option><?php foreach ($categories as $c): ?><option value="<?= e($c['slug']) ?>" <?= ($f['category'] ?? '') === $c['slug'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <select name="published" aria-label="الحالة"><option value="">كل الحالات</option><option value="1" <?= ($f['published'] ?? '') === '1' ? 'selected' : '' ?>>منشورة</option><option value="0" <?= ($f['published'] ?? '') === '0' ? 'selected' : '' ?>>مخفية</option></select>
    <select name="sort" aria-label="الترتيب">
      <?php foreach (['updated' => 'آخر تعديل', 'newest' => 'الأحدث إضافة', 'title' => 'أبجدي', 'downloads' => 'الأكثر تحميلاً', 'rating' => 'التقييم', 'year' => 'سنة الإصدار'] as $k => $l): ?><option value="<?= $k ?>" <?= ($f['sort'] ?? 'updated') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-sm" type="submit"><?= icon('search') ?>تصفية</button>
  </form>
  <span class="spacer"></span>
  <?php if (can('games.import')): ?><a class="btn btn-sm" href="<?= e(url('/admin/import')) ?>"><?= icon('file-sheet') ?>استيراد</a><?php endif; ?>
  <?php if (can('games.create')): ?><a class="btn btn-sm btn-primary" href="<?= e(url('/admin/games/create')) ?>"><?= icon('plus') ?>إضافة لعبة</a><?php endif; ?>
</div>

<form method="post" action="<?= e(url('/admin/games/bulk')) ?>" data-bulk>
  <?= csrf_field() ?>
  <div class="bulkbar glass" hidden data-bulkbar>
    <b data-bulk-count>0</b> محددة
    <span class="spacer"></span>
    <?php if (can('games.publish')): ?>
      <button class="btn btn-xs" name="action" value="publish" type="submit"><?= icon('eye') ?>نشر</button>
      <button class="btn btn-xs" name="action" value="hide" type="submit"><?= icon('eye-off') ?>إخفاء</button>
      <button class="btn btn-xs" name="action" value="feature" type="submit"><?= icon('star') ?>تمييز</button>
      <button class="btn btn-xs" name="action" value="unfeature" type="submit">إلغاء التمييز</button>
    <?php endif; ?>
    <?php if (can('games.delete')): ?><button class="btn btn-xs btn-danger" name="action" value="delete" type="submit" data-confirm-click="حذف الألعاب المحددة وكل ملفاتها نهائياً؟"><?= icon('trash') ?>حذف</button><?php endif; ?>
  </div>
  <div class="glass table-wrap">
    <table class="table">
      <thead><tr><th><input type="checkbox" data-check-all aria-label="تحديد الكل"></th><th>اللعبة</th><th>الجهاز</th><th>الحالة</th><th>الملفات</th><th>التقييم</th><th>التنزيلات</th><th>آخر تعديل</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($result['items'] as $g): ?>
        <tr>
          <td><input type="checkbox" name="ids[]" value="<?= (int) $g['id'] ?>" data-check aria-label="تحديد <?= e($g['title']) ?>"></td>
          <td><div class="g-row"><img src="<?= e(cover_url($g, 'sm')) ?>" alt="" loading="lazy"><div><a href="<?= e(url('/admin/games/' . (int) $g['id'] . '/edit')) ?>"><b><?= e($g['title']) ?></b></a><small><?= e(trim(($g['developer'] ?? '') . ' ' . ($g['release_year'] ? '(' . $g['release_year'] . ')' : ''))) ?><?= $g['product_code'] ? ' — ' . e($g['product_code']) : '' ?></small></div></div></td>
          <td><span class="badge" style="--pc: <?= e($g['platform_color']) ?>"><?= e($g['platform_short']) ?></span></td>
          <td><?= $g['is_published'] ? '<span class="tag tag-ok">منشورة</span>' : '<span class="tag tag-warn">مخفية</span>' ?><?= $g['is_featured'] ? ' <span class="tag tag-info">مميزة</span>' : '' ?></td>
          <td><span class="tag <?= $g['files_status'] === 'available' ? 'tag-ok' : '' ?>"><?= e(files_status_names()[$g['files_status']] ?? '') ?></span></td>
          <td><?= $g['rating'] !== null ? e(number_format((float) $g['rating'], 1)) : '—' ?></td>
          <td><?= number_format((int) $g['download_count']) ?></td>
          <td class="muted nowrap"><?= e(time_ago($g['updated_at'])) ?></td>
          <td><div class="actions">
            <a class="btn btn-xs" href="<?= e(url('/admin/games/' . (int) $g['id'] . '/edit')) ?>"><?= icon('edit') ?>تعديل</a>
            <a class="btn btn-xs btn-ghost" href="<?= e(url('game/' . $g['slug'])) ?>" target="_blank" rel="noopener" aria-label="عرض في الموقع"><?= icon('external') ?></a>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$result['items']): ?><?= partial('empty', ['icon' => 'library', 'heading' => 'لا توجد ألعاب مطابقة', 'action' => can('games.create') ? ['/admin/games/create', 'إضافة لعبة'] : null]) ?><?php endif; ?>
  </div>
</form>
<p class="muted" style="font-size:var(--fs-sm)"><?= number_format((int) $result['total']) ?> <?= ar_noun((int) $result['total'], 'لعبة', 'ألعاب') ?></p>
<?= partial('pagination', ['result' => $result]) ?>
