<div class="dash">
  <section class="glass card">
    <h2><?= icon('bell-ring') ?>الإشعارات المرسلة</h2>
    <?php if (!$result['items']): ?><p class="muted">لم تُرسل إشعارات بعد.</p><?php else: ?>
    <ul class="list"><?php foreach ($result['items'] as $n): ?>
      <li><span class="notif"><span class="ico"><?= icon($n['user_id'] ? 'user' : 'megaphone') ?></span></span>
        <div class="grow"><b><?= e($n['title']) ?></b><small><?= $n['user_id'] ? 'إلى ' . e($n['target']) : 'إلى الجميع' ?> — من <?= e($n['sender'] ?? '—') ?> — <?= e(time_ago($n['created_at'])) ?></small><?php if ($n['body']): ?><small style="display:block"><?= e($n['body']) ?></small><?php endif; ?></div>
        <form method="post" action="<?= e(url('/admin/notifications/' . (int) $n['id'] . '/delete')) ?>" data-confirm="حذف الإشعار؟"><?= csrf_field() ?><button class="btn btn-xs btn-danger btn-icon" type="submit" aria-label="حذف"><?= icon('trash') ?></button></form></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
    <?= partial('pagination', ['result' => $result]) ?>
  </section>
  <form method="post" action="<?= e(url('/admin/notifications')) ?>" class="glass card" style="align-self:start"><?= csrf_field() ?>
    <h2><?= icon('send') ?>إرسال إشعار</h2>
    <div class="stack">
      <label class="field"><span>المستلمون</span><select name="audience" data-audience><option value="all">كل المستخدمين</option><option value="user">مستخدم محدد</option></select></label>
      <label class="field<?= field_error('user') ? ' has-error' : '' ?>" data-user-field><span>اسم المستخدم أو البريد أو الرقم</span><input type="text" name="user" value="<?= e(old('user')) ?>" dir="ltr"><?php if ($er = field_error('user')): ?><small class="error"><?= e($er) ?></small><?php endif; ?></label>
      <label class="field<?= field_error('title') ? ' has-error' : '' ?>"><span>العنوان *</span><input type="text" name="title" value="<?= e(old('title')) ?>" required maxlength="190"></label>
      <label class="field"><span>النص</span><textarea name="body" rows="3" maxlength="1000"><?= e(old('body')) ?></textarea></label>
      <label class="field<?= field_error('link') ? ' has-error' : '' ?>"><span>رابط (اختياري)</span><input type="text" name="link" value="<?= e(old('link')) ?>" dir="ltr" placeholder="/game/... أو https://"><?php if ($er = field_error('link')): ?><small class="error"><?= e($er) ?></small><?php endif; ?></label>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('send') ?>إرسال</button></div>
    <p class="muted" style="font-size:var(--fs-xs)">تظهر الإشعارات في الموقع وتطبيق الجوال وتطبيق PS4 عبر <span class="code-inline">/api/v1/user/notifications</span>.</p>
  </form>
</div>
