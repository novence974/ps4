<?php $u = $edit; $f = static fn (string $k, $d = '') => old($k, $u[$k] ?? $d); ?>
<div class="dash">
  <section>
    <div class="toolbar"><form method="get"><select name="type" data-autosubmit aria-label="النوع"><option value="">كل الأنواع</option><?php foreach (update_type_names() as $k => $l): ?><option value="<?= $k ?>" <?= (App\Core\Request::$current?->query('type')) === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></form></div>
    <div class="feed">
      <?php foreach ($result['items'] as $up): ?>
        <article class="feed-item glass">
          <span class="ico"><?= icon($up['type'] === 'maintenance' ? 'cog' : ($up['game_id'] ? 'refresh' : 'megaphone')) ?></span>
          <div>
            <div class="row" style="gap:6px"><span class="tag tag-info"><?= e(update_type_names()[$up['type']] ?? '') ?></span><?= $up['is_published'] ? '<span class="tag tag-ok">منشور</span>' : '<span class="tag tag-warn">مسودة</span>' ?><?php if ($up['game_title']): ?><span class="tag"><?= e($up['game_title']) ?></span><?php endif; ?></div>
            <h3><?= e($up['title']) ?></h3>
            <?php if ($up['body']): ?><p class="text"><?= e(mb_substr($up['body'], 0, 240)) ?></p><?php endif; ?>
            <div class="row" style="margin-top:6px"><span class="when"><?= e(fmt_date($up['published_at'] ?? $up['created_at'], 'Y/m/d H:i')) ?></span><span class="spacer"></span>
              <a class="btn btn-xs" href="?edit=<?= (int) $up['id'] ?>"><?= icon('edit') ?>تعديل</a>
              <form method="post" action="<?= e(url('/admin/updates/' . (int) $up['id'] . '/delete')) ?>" data-confirm="حذف هذا التحديث؟"><?= csrf_field() ?><button class="btn btn-xs btn-danger btn-icon" type="submit" aria-label="حذف"><?= icon('trash') ?></button></form></div>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$result['items']): ?><div class="glass"><?= partial('empty', ['icon' => 'sparkles', 'heading' => 'لا توجد تحديثات']) ?></div><?php endif; ?>
    </div>
    <?= partial('pagination', ['result' => $result]) ?>
  </section>
  <form method="post" action="<?= e(url($u ? '/admin/updates/' . (int) $u['id'] : '/admin/updates')) ?>" class="glass card" style="align-self:start"><?= csrf_field() ?>
    <h2><?= icon($u ? 'edit' : 'plus') ?><?= $u ? 'تعديل التحديث' : 'نشر تحديث جديد' ?></h2>
    <div class="stack">
      <label class="field"><span>النوع</span><select name="type"><?php foreach (update_type_names() as $k => $l): ?><option value="<?= $k ?>" <?= $f('type', 'announcement') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label class="field<?= field_error('title') ? ' has-error' : '' ?>"><span>العنوان *</span><input type="text" name="title" value="<?= e($f('title')) ?>" required maxlength="190"><?php if ($er = field_error('title')): ?><small class="error"><?= e($er) ?></small><?php endif; ?></label>
      <label class="field"><span>التفاصيل</span><textarea name="body" rows="5" maxlength="10000"><?= e($f('body')) ?></textarea></label>
      <label class="field"><span>اللعبة المرتبطة (اختياري)</span><select name="game_id"><option value="">—</option><?php foreach ($games as $g): ?><option value="<?= (int) $g['id'] ?>" <?= (int) $f('game_id') === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['title']) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>الجهاز (اختياري)</span><select name="platform_id"><option value="">—</option><?php foreach ($platforms as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $f('platform_id') === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></label>
      <input type="hidden" name="is_published" value="0"><label class="switch"><span>منشور</span><input type="checkbox" name="is_published" value="1" <?= $f('is_published', 1) ? 'checked' : '' ?>></label>
      <input type="hidden" name="notify_favorites" value="0"><label class="switch"><span>إشعار من أضاف اللعبة للمفضلة<small>يعمل عند ربط التحديث بلعبة</small></span><input type="checkbox" name="notify_favorites" value="1" checked></label>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('send') ?><?= $u ? 'حفظ' : 'نشر' ?></button><?php if ($u): ?><a class="btn btn-ghost" href="<?= e(url('/admin/updates')) ?>">إلغاء</a><?php endif; ?></div>
  </form>
</div>
