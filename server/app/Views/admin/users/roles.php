<?php $m = $matrix; $isSuper = auth_user()['role_slug'] === 'super_admin'; $group = null;
$groups = ['general' => 'عام', 'games' => 'الألعاب', 'files' => 'الملفات', 'catalog' => 'الكتالوج', 'users' => 'المستخدمون', 'system' => 'النظام']; ?>
<div class="notice" style="margin-bottom:16px"><?= icon('shield') ?><span>المدير الرئيسي يملك كل الصلاحيات دائماً. التعديل متاح للمدير الرئيسي فقط، وتُطبق التغييرات فوراً على كل الجلسات والتطبيقات.</span></div>
<form method="post" action="<?= e(url('/admin/roles')) ?>" class="glass card"><?= csrf_field() ?>
  <div class="table-wrap"><table class="table matrix">
    <thead><tr><th>الصلاحية</th><?php foreach ($m['roles'] as $r): ?><th><?= e($r['name']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($m['permissions'] as $p): if ($p['group_name'] !== $group): $group = $p['group_name']; ?>
      <tr class="grp"><td colspan="<?= count($m['roles']) + 1 ?>"><?= e($groups[$group] ?? $group) ?></td></tr>
    <?php endif; ?>
      <tr><td><?= e($p['name']) ?> <span class="code-inline"><?= e($p['slug']) ?></span></td>
        <?php foreach ($m['roles'] as $r): $on = $r['slug'] === 'super_admin' || !empty($m['map'][(int) $r['id']][(int) $p['id']]); ?>
          <td><input type="checkbox" name="perm[<?= (int) $r['id'] ?>][<?= (int) $p['id'] ?>]" value="1" <?= $on ? 'checked' : '' ?> <?= ($r['slug'] === 'super_admin' || !$isSuper) ? 'disabled' : '' ?> aria-label="<?= e($r['name'] . ': ' . $p['name']) ?>"></td>
        <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($isSuper): ?><div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('save') ?>حفظ الصلاحيات</button></div><?php endif; ?>
</form>
