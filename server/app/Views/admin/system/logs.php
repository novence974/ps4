<?php $f = $filters; ?>
<div class="toolbar"><form method="get">
  <input type="search" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="وصف، اسم مستخدم أو IP" aria-label="بحث">
  <select name="action" aria-label="النوع"><option value="">كل الأنواع</option><?php foreach (['auth' => 'الدخول والخروج', 'user' => 'المستخدمون', 'game' => 'الألعاب', 'file' => 'الملفات', 'upload' => 'الرفع', 'backup' => 'النسخ الاحتياطي', 'settings' => 'الإعدادات'] as $k => $l): ?><option value="<?= $k ?>" <?= ($f['action'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <input type="date" name="from" value="<?= e($f['from'] ?? '') ?>" aria-label="من تاريخ">
  <button class="btn btn-sm" type="submit"><?= icon('search') ?>تصفية</button></form>
  <span class="spacer"></span><a class="btn btn-sm" href="<?= e(url('/admin/logs/errors')) ?>"><?= icon('alert') ?>سجل الأخطاء</a></div>
<div class="glass table-wrap"><table class="table"><thead><tr><th>الوقت</th><th>الحدث</th><th>الوصف</th><th>المستخدم</th><th>IP</th></tr></thead><tbody>
  <?php foreach ($result['items'] as $a): ?><tr><td class="nowrap muted"><?= e(fmt_date($a['created_at'], 'Y/m/d H:i:s')) ?></td><td><span class="tag"><?= e(App\Services\ActivityLog::label($a['action'])) ?></span></td><td><?= e($a['description'] ?? '') ?></td><td><?= e($a['username'] ?? 'النظام') ?></td><td class="mono"><?= e($a['ip'] ?? '') ?></td></tr><?php endforeach; ?>
</tbody></table><?php if (!$result['items']): ?><?= partial('empty', ['icon' => 'activity', 'heading' => 'لا توجد سجلات مطابقة']) ?><?php endif; ?></div>
<p class="muted" style="font-size:var(--fs-sm)"><?= number_format((int) $result['total']) ?> سجل</p>
<?= partial('pagination', ['result' => $result]) ?>
