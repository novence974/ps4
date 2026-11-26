<div class="notice" style="margin-bottom:16px"><?= icon('info') ?><span>تُكتشف الألعاب المكررة بمقارنة الاسم الموحّد (بدون مسافات أو رموز أو حالة أحرف) على نفس الجهاز، وبمقارنة رمز المنتج. قد تكون بعض النتائج إصدارات مناطق مختلفة مقصودة.</span></div>
<?php if (!$groups): ?>
  <div class="glass"><?= partial('empty', ['icon' => 'badge', 'heading' => 'لا توجد ألعاب مكررة', 'text' => 'المكتبة نظيفة من التكرار حالياً.']) ?></div>
<?php else: ?>
  <?php foreach ($groups as $grp): ?>
    <section class="glass card">
      <h2><?= icon('compare') ?><?= $grp['kind'] === 'code' ? 'رمز منتج مكرر' : 'اسم مكرر' ?>: <span class="code-inline"><?= e($grp['ref']) ?></span> <span class="badge"><?= e($grp['short_name']) ?></span> <span class="muted" style="font-size:var(--fs-sm)"><?= (int) $grp['c'] ?> ألعاب</span></h2>
      <div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>الاسم</th><th>المنطقة</th><th>رمز المنتج</th><th>السنة</th><th>الحالة</th><th>أضيفت</th><th></th></tr></thead><tbody>
        <?php foreach ($grp['games'] as $g): ?>
          <tr><td class="mono"><?= (int) $g['id'] ?></td><td><b><?= e($g['title']) ?></b></td><td><?= e(region_name($g['region'])) ?></td><td class="mono"><?= e($g['product_code'] ?? '—') ?></td><td><?= e((string) ($g['release_year'] ?? '—')) ?></td>
            <td><?= $g['is_published'] ? '<span class="tag tag-ok">منشورة</span>' : '<span class="tag tag-warn">مخفية</span>' ?></td><td class="muted"><?= e(fmt_date($g['created_at'])) ?></td>
            <td><div class="actions"><a class="btn btn-xs" href="<?= e(url('/admin/games/' . (int) $g['id'] . '/edit')) ?>"><?= icon('edit') ?>فتح</a>
              <?php if (can('games.delete')): ?><form method="post" action="<?= e(url('/admin/games/' . (int) $g['id'] . '/delete')) ?>" data-confirm="حذف هذه النسخة المكررة نهائياً؟"><?= csrf_field() ?><button class="btn btn-xs btn-danger" type="submit"><?= icon('trash') ?>حذف</button></form><?php endif; ?></div></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </section>
  <?php endforeach; ?>
<?php endif; ?>
