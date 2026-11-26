<?php $me = auth_user(); $self = (int) $me['id'] === (int) $u['id']; $locked = $u['locked_until'] && strtotime($u['locked_until'] . ' UTC') > time(); ?>
<div class="dash">
  <div>
    <section class="glass card">
      <div class="row"><img class="avatar" src="<?= e(avatar_url($u)) ?>" alt="" style="width:72px;height:72px"><div><h2 style="margin:0"><?= e(user_label($u)) ?></h2><span class="muted ltr">@<?= e($u['username']) ?> — <?= e($u['email']) ?></span></div></div>
      <dl class="kv" style="margin-top:16px">
        <dt>الدور</dt><dd><?= e($u['role_name']) ?></dd>
        <dt>الحالة</dt><dd><?= $u['status'] === 'active' ? 'نشط' : 'موقوف' ?><?= $locked ? ' — مقفل حتى ' . e(fmt_date($u['locked_until'], 'Y/m/d H:i')) : '' ?></dd>
        <dt>البريد</dt><dd><?= $u['email_verified_at'] ? 'مؤكد في ' . e(fmt_date($u['email_verified_at'])) : 'غير مؤكد' ?></dd>
        <dt>آخر دخول</dt><dd><?= $u['last_login_at'] ? e(fmt_date($u['last_login_at'], 'Y/m/d H:i')) . ' — <span class="mono">' . e($u['last_login_ip']) . '</span>' : '—' ?></dd>
        <dt>التسجيل</dt><dd><?= e(fmt_date($u['created_at'], 'Y/m/d H:i')) ?></dd>
        <dt>الإحصاءات</dt><dd><?= (int) $stats['favorites'] ?> <?= ar_noun((int) $stats['favorites'], 'مفضلة', 'مفضلات') ?> — <?= (int) $stats['downloads'] ?> <?= ar_noun((int) $stats['downloads'], 'تنزيل', 'تنزيلات') ?> — <?= (int) $stats['viewed'] ?> <?= ar_noun((int) $stats['viewed'], 'مشاهدة', 'مشاهدات') ?></dd>
      </dl>
    </section>
    <section class="glass card"><h2><?= icon('activity') ?>آخر النشاط</h2>
      <?php if (!$activity): ?><p class="muted">لا يوجد نشاط.</p><?php else: ?>
      <ul class="list"><?php foreach ($activity as $a): ?><li><span class="tag"><?= e(App\Services\ActivityLog::label($a['action'])) ?></span><div class="grow"><small><?= e($a['description'] ?? '') ?></small></div><small class="mono"><?= e($a['ip'] ?? '') ?></small><small><?= e(time_ago($a['created_at'])) ?></small></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </section>
    <section class="glass card"><h2><?= icon('download') ?>آخر التنزيلات</h2>
      <?php if (!$downloads): ?><p class="muted">لا توجد تنزيلات.</p><?php else: ?>
      <ul class="list"><?php foreach ($downloads as $d): ?><li><div class="grow"><b><?= e($d['title']) ?></b><small><?= e($d['original_name'] ?? '') ?></small></div><small><?= e(fmt_date($d['created_at'], 'Y/m/d H:i')) ?></small></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </section>
  </div>
  <div>
    <?php if (can('users.manage') && !$self): ?>
    <form method="post" action="<?= e(url('/admin/users/' . (int) $u['id'])) ?>" class="glass card"><?= csrf_field() ?>
      <h2><?= icon('user-cog') ?>الصلاحيات والحالة</h2>
      <div class="stack">
        <?php if (can('roles.assign')): ?>
        <label class="field"><span>الدور</span><select name="role_id"><?php foreach ($roles as $r): $disabled = $me['role_slug'] !== 'super_admin' && (int) $r['level'] >= (int) $me['role_level']; ?><option value="<?= (int) $r['id'] ?>" <?= (int) $u['role_id'] === (int) $r['id'] ? 'selected' : '' ?> <?= $disabled && (int) $u['role_id'] !== (int) $r['id'] ? 'disabled' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <label class="field"><span>حالة الحساب</span><select name="status"><option value="active" <?= $u['status'] === 'active' ? 'selected' : '' ?>>نشط</option><option value="suspended" <?= $u['status'] === 'suspended' ? 'selected' : '' ?>>موقوف (يلغي جلسات تطبيقاته)</option></select></label>
        <label class="field"><span>الاسم الظاهر</span><input type="text" name="display_name" value="<?= e($u['display_name']) ?>" maxlength="64"></label>
        <input type="hidden" name="can_download" value="0"><label class="switch"><span>السماح بالتنزيل</span><input type="checkbox" name="can_download" value="1" <?= $u['can_download'] ? 'checked' : '' ?>></label>
        <input type="hidden" name="email_verified" value="0"><label class="switch"><span>البريد مؤكد</span><input type="checkbox" name="email_verified" value="1" <?= $u['email_verified_at'] ? 'checked' : '' ?>></label>
        <?php if ($locked): ?><label class="check"><input type="checkbox" name="unlock" value="1">فك القفل المؤقت</label><?php endif; ?>
      </div>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('save') ?>حفظ</button></div>
    </form>
    <form method="post" action="<?= e(url('/admin/users/' . (int) $u['id'] . '/delete')) ?>" class="glass card" data-confirm="حذف الحساب <?= e($u['username']) ?> نهائياً مع مفضلته وسجلاته؟"><?= csrf_field() ?>
      <h2><?= icon('trash') ?>حذف الحساب</h2><p class="muted" style="font-size:var(--fs-sm)">يحذف الحساب نهائياً. تبقى سجلات التنزيل للإحصاءات دون ربطها بالحساب.</p>
      <button class="btn btn-danger" type="submit"><?= icon('trash') ?>حذف الحساب</button>
    </form>
    <?php elseif ($self): ?><div class="notice"><?= icon('info') ?><span>هذا حسابك. عدّل بياناتك من <a href="<?= e(url('/account/edit')) ?>">إعدادات الحساب</a>.</span></div><?php endif; ?>
    <section class="glass card"><h2><?= icon('phone') ?>الأجهزة المتصلة</h2>
      <?php if (!$tokens): ?><p class="muted">لا توجد.</p><?php else: ?><ul class="list"><?php foreach ($tokens as $t): ?><li><div class="grow"><b><?= e($t['name']) ?></b><small><?= e(time_ago($t['last_used_at'])) ?> — <span class="mono"><?= e($t['last_used_ip'] ?? '') ?></span></small></div></li><?php endforeach; ?></ul><?php endif; ?>
    </section>
  </div>
</div>
