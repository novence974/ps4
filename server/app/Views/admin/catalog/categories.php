<?php $c = $edit; $f = static fn (string $k, $d = '') => old($k, $c[$k] ?? $d); ?>
<div class="dash">
  <section class="glass card">
    <h2><?= icon('tag') ?>التصنيفات (<?= count($categories) ?>)</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th></th><th>الاسم</th><th>المعرف</th><th>الألعاب</th><th>الترتيب</th><th></th></tr></thead><tbody>
      <?php foreach ($categories as $cat): ?>
        <tr><td><span class="cat" style="--cc: <?= e($cat['color']) ?>; padding:0; border:0; background:none"><span class="ico"><?= icon('cat-' . ($cat['icon'] ?: 'bolt')) ?></span></span></td>
          <td><b><?= e($cat['name']) ?></b><br><small class="muted"><?= e($cat['name_en']) ?></small></td><td class="mono"><?= e($cat['slug']) ?></td><td><?= (int) $cat['games_count'] ?></td><td><?= (int) $cat['sort_order'] ?></td>
          <td><div class="actions"><a class="btn btn-xs" href="?edit=<?= (int) $cat['id'] ?>"><?= icon('edit') ?>تعديل</a>
            <form method="post" action="<?= e(url('/admin/categories/' . (int) $cat['id'] . '/delete')) ?>" data-confirm="حذف التصنيف «<?= e($cat['name']) ?>»؟ لن تُحذف الألعاب."><?= csrf_field() ?><button class="btn btn-xs btn-danger btn-icon" type="submit" aria-label="حذف"><?= icon('trash') ?></button></form></div></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>
  <form method="post" action="<?= e(url($c ? '/admin/categories/' . (int) $c['id'] : '/admin/categories')) ?>" class="glass card"><?= csrf_field() ?>
    <h2><?= icon($c ? 'edit' : 'plus') ?><?= $c ? 'تعديل: ' . e($c['name']) : 'تصنيف جديد' ?></h2>
    <div class="stack">
      <label class="field<?= field_error('name') ? ' has-error' : '' ?>"><span>الاسم بالعربية *</span><input type="text" name="name" value="<?= e($f('name')) ?>" required maxlength="64"><?php if ($er = field_error('name')): ?><small class="error"><?= e($er) ?></small><?php endif; ?></label>
      <label class="field"><span>الاسم بالإنجليزية</span><input type="text" name="name_en" value="<?= e($f('name_en')) ?>" maxlength="64" dir="ltr"></label>
      <label class="field<?= field_error('slug') ? ' has-error' : '' ?>"><span>المعرف *</span><input type="text" name="slug" value="<?= e($f('slug')) ?>" required maxlength="64" dir="ltr" pattern="[a-z0-9]+(-[a-z0-9]+)*"><?php if ($er = field_error('slug')): ?><small class="error"><?= e($er) ?></small><?php endif; ?></label>
      <label class="field"><span>الأيقونة</span><select name="icon"><?php foreach (['bolt' => 'برق', 'compass' => 'بوصلة', 'globe' => 'كرة أرضية', 'ghost' => 'شبح', 'flag' => 'علم', 'ball' => 'مرمى', 'trophy' => 'كأس', 'fist' => 'سيوف', 'home' => 'منزل', 'star' => 'لعبة أطفال', 'sword' => 'سيف', 'chess' => 'قلعة'] as $k => $l): ?><option value="<?= $k ?>" <?= $f('icon') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <div class="grid-2"><label class="field"><span>اللون</span><input type="color" name="color" value="<?= e($f('color', '#8b7cff')) ?>"></label><label class="field"><span>الترتيب</span><input type="number" name="sort_order" value="<?= e($f('sort_order', 0)) ?>"></label></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('save') ?>حفظ</button><?php if ($c): ?><a class="btn btn-ghost" href="<?= e(url('/admin/categories')) ?>">إلغاء</a><?php endif; ?></div>
  </form>
</div>
