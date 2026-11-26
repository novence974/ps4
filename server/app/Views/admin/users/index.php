<?php $f = $filters; ?>
<div class="toolbar">
  <form method="get">
    <input type="search" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="اسم المستخدم أو البريد" aria-label="بحث">
    <select name="role" aria-label="الدور"><option value="">كل الأدوار</option><?php foreach ($roles as $r): ?><option value="<?= e($r['slug']) ?>" <?= ($f['role'] ?? '') === $r['slug'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
    <select name="status" aria-label="الحالة"><option value="">كل الحالات</option><option value="active" <?= ($f['status'] ?? '') === 'active' ? 'selected' : '' ?>>نشط</option><option value="suspended" <?= ($f['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>موقوف</option></select>
    <select name="verified" aria-label="البريد"><option value="">البريد: الكل</option><option value="1" <?= ($f['verified'] ?? '') === '1' ? 'selected' : '' ?>>مؤكد</option><option value="0" <?= ($f['verified'] ?? '') === '0' ? 'selected' : '' ?>>غير مؤكد</option></select>
    <button class="btn btn-sm" type="submit"><?= icon('search') ?>تصفية</button>
  </form>
  <span class="spacer"></span>
  <?php if (can('users.manage')): ?><button class="btn btn-sm btn-primary" type="button" data-toggle="#new-user"><?= icon('user-plus') ?>حساب جديد</button><?php endif; ?>
</div>
<?php if (can('users.manage')): ?>
<form id="new-user" method="post" action="<?= e(url('/admin/users/create')) ?>" class="glass card" <?= field_error('username') || field_error('email') || field_error('password') ? '' : 'hidden' ?>><?= csrf_field() ?>
  <h2><?= icon('user-plus') ?>إنشاء حساب</h2>
  <div class="inline-form">
    <label class="field<?= field_error('username') ? ' has-error' : '' ?>"><span>اسم المستخدم</span><input type="text" name="username" value="<?= e(old('username')) ?>" required dir="ltr" maxlength="32"><?php if ($er = field_error('username')): ?><small class="error"><?= e($er) ?></small><?php endif; ?></label>
    <label class="field<?= field_error('email') ? ' has-error' : '' ?>"><span>البريد</span><input type="email" name="email" value="<?= e(old('email')) ?>" required dir="ltr"><?php if ($er = field_error('email')): ?><small class="error"><?= e($er) ?></small><?php endif; ?></label>
    <label class="field<?= field_error('password') ? ' has-error' : '' ?>"><span>كلمة المرور</span><input type="password" name="password" required minlength="8" autocomplete="new-password"><?php if ($er = field_error('password')): ?><small class="error"><?= e($er) ?></small><?php endif; ?></label>
    <label class="field"><span>الدور</span><select name="role_id"><?php foreach ($roles as $r): if ($r['slug'] === 'super_admin' && auth_user()['role_slug'] !== 'super_admin') { continue; } ?><option value="<?= (int) $r['id'] ?>" <?= $r['slug'] === 'user' ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></label>
    <button class="btn btn-primary" type="submit"><?= icon('save') ?>إنشاء</button>
  </div>
</form>
<?php endif; ?>
<div class="glass table-wrap">
  <table class="table">
    <thead><tr><th>المستخدم</th><th>الدور</th><th>الحالة</th><th>البريد</th><th>التنزيلات</th><th>آخر دخول</th><th>التسجيل</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($result['items'] as $u): $locked = $u['locked_until'] && strtotime($u['locked_until'] . ' UTC') > time(); ?>
      <tr>
        <td><div class="g-row" style="min-width:200px"><img class="avatar" src="<?= e(avatar_url($u)) ?>" alt="" style="width:38px;height:38px"><div><b><?= e(user_label($u)) ?></b><small class="ltr">@<?= e($u['username']) ?> — <?= e($u['email']) ?></small></div></div></td>
        <td><span class="tag tag-info"><?= e($u['role_name']) ?></span></td>
        <td><?= $u['status'] === 'active' ? '<span class="tag tag-ok">نشط</span>' : '<span class="tag tag-bad">موقوف</span>' ?><?= $locked ? ' <span class="tag tag-warn">مقفل مؤقتاً</span>' : '' ?><?= $u['can_download'] ? '' : ' <span class="tag">التنزيل ممنوع</span>' ?></td>
        <td><?= $u['email_verified_at'] ? '<span class="tag tag-ok">مؤكد</span>' : '<span class="tag tag-warn">غير مؤكد</span>' ?></td>
        <td><?= (int) $u['downloads_count'] ?></td>
        <td class="muted nowrap"><?= $u['last_login_at'] ? e(time_ago($u['last_login_at'])) : '—' ?></td>
        <td class="muted nowrap"><?= e(fmt_date($u['created_at'])) ?></td>
        <td><a class="btn btn-xs" href="<?= e(url('/admin/users/' . (int) $u['id'])) ?>"><?= icon('edit') ?>إدارة</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="muted" style="font-size:var(--fs-sm)"><?= number_format((int) $result['total']) ?> <?= ar_noun((int) $result['total'], 'مستخدم', 'مستخدمين') ?></p>
<?= partial('pagination', ['result' => $result]) ?>
