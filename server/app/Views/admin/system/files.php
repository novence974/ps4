<?php $f = $filters; ?>
<div class="toolbar"><form method="get">
  <input type="search" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="اسم الملف أو اللعبة أو SHA-256" aria-label="بحث">
  <select name="type" aria-label="النوع"><option value="">كل الأنواع</option><?php foreach (file_type_names() as $k => $l): ?><option value="<?= $k ?>" <?= ($f['type'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <button class="btn btn-sm" type="submit"><?= icon('search') ?>بحث</button></form>
  <span class="spacer"></span><a class="btn btn-sm btn-primary" href="<?= e(url('/admin/uploads')) ?>"><?= icon('cloud-upload') ?>رفع ملفات</a></div>
<div class="glass table-wrap"><table class="table"><thead><tr><th>الملف</th><th>اللعبة</th><th>النوع</th><th>الحجم</th><th>القرص</th><th>الصلاحية</th><th>التنزيلات</th><th>أضيف</th><th></th></tr></thead><tbody>
  <?php foreach ($result['items'] as $x): ?>
    <tr><td><b class="ltr" style="overflow-wrap:anywhere"><?= e($x['original_name']) ?></b><br><small class="mono faint"><?= e(substr((string) $x['sha256'], 0, 24)) ?>…</small></td>
      <td><a href="<?= e(url('/admin/games/' . (int) $x['game_id'] . '/edit?tab=files')) ?>"><?= e($x['game_title']) ?></a></td>
      <td><?= e(file_type_names()[$x['file_type']] ?? '') ?></td><td class="ltr nowrap"><?= e(human_bytes((int) $x['size_bytes'])) ?></td><td class="mono"><?= e($x['disk']) ?></td>
      <td><?= e(access_names()[$x['access_level']] ?? '') ?></td><td><?= (int) $x['download_count'] ?></td><td class="muted nowrap"><?= e(fmt_date($x['created_at'])) ?></td>
      <td><div class="actions">
        <form method="post" action="<?= e(url('/admin/files/' . (int) $x['id'] . '/verify')) ?>"><?= csrf_field() ?><button class="btn btn-xs" type="submit"><?= icon('shield') ?>تحقق</button></form>
        <form method="post" action="<?= e(url('/admin/files/' . (int) $x['id'] . '/delete')) ?>" data-confirm="حذف الملف نهائياً من الخادم؟"><?= csrf_field() ?><button class="btn btn-xs btn-danger btn-icon" type="submit" aria-label="حذف"><?= icon('trash') ?></button></form></div></td></tr>
  <?php endforeach; ?>
</tbody></table>
<?php if (!$result['items']): ?><?= partial('empty', ['icon' => 'files', 'heading' => 'لا توجد ملفات']) ?><?php endif; ?></div>
<p class="muted" style="font-size:var(--fs-sm)"><?= number_format((int) $result['total']) ?> <?= ar_noun((int) $result['total'], 'ملف', 'ملفات') ?></p>
<?= partial('pagination', ['result' => $result]) ?>
