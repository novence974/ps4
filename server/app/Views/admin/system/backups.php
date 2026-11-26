<div class="dash">
  <div>
    <section class="glass card"><h2><?= icon('archive') ?>النسخ المتوفرة (<?= count($backups) ?>)</h2>
      <?php if (!$backups): ?><p class="muted">لا توجد نسخ احتياطية بعد.</p><?php else: ?>
      <div class="table-wrap"><table class="table"><thead><tr><th>الملف</th><th>النوع</th><th>الحجم</th><th>التاريخ</th><th></th></tr></thead><tbody>
        <?php foreach ($backups as $b): ?>
          <tr><td class="mono" style="overflow-wrap:anywhere"><?= e($b['name']) ?></td><td><?= $b['type'] === 'full' ? '<span class="tag tag-info">كاملة</span>' : '<span class="tag">قاعدة البيانات</span>' ?></td><td class="ltr"><?= e(human_bytes($b['size'])) ?></td><td class="nowrap"><?= e(fmt_date($b['created_at'], 'Y/m/d H:i')) ?></td>
            <td><div class="actions">
              <a class="btn btn-xs" href="<?= e(url('/admin/backups/' . rawurlencode($b['name']) . '/download')) ?>"><?= icon('download') ?>تنزيل</a>
              <?php if (can('backups.restore')): ?><button class="btn btn-xs" type="button" data-toggle="#restore-<?= e(md5($b['name'])) ?>"><?= icon('undo') ?>استعادة</button><?php endif; ?>
              <form method="post" action="<?= e(url('/admin/backups/' . rawurlencode($b['name']) . '/delete')) ?>" data-confirm="حذف النسخة <?= e($b['name']) ?>؟"><?= csrf_field() ?><button class="btn btn-xs btn-danger btn-icon" type="submit" aria-label="حذف"><?= icon('trash') ?></button></form>
            </div></td></tr>
          <?php if (can('backups.restore')): ?>
          <tr id="restore-<?= e(md5($b['name'])) ?>" hidden><td colspan="5">
            <form method="post" action="<?= e(url('/admin/backups/' . rawurlencode($b['name']) . '/restore')) ?>" class="inline-form" data-confirm="ستُستبدل قاعدة البيانات الحالية بمحتوى هذه النسخة (مع نسخة أمان تلقائية قبلها). متابعة؟"><?= csrf_field() ?>
              <div class="notice warn" style="grid-column:1/-1"><?= icon('alert') ?><span>الاستعادة تستبدل كل بيانات المكتبة الحالية. تُنشأ نسخة أمان تلقائياً قبل التنفيذ.</span></div>
              <label class="field"><span>كلمة مرورك للتأكيد</span><input type="password" name="password" required autocomplete="current-password"></label>
              <?php if ($b['type'] === 'full'): ?><input type="hidden" name="with_media" value="0"><label class="check"><input type="checkbox" name="with_media" value="1" checked>استعادة الصور والوسائط أيضاً</label><?php endif; ?>
              <button class="btn btn-danger" type="submit"><?= icon('undo') ?>تنفيذ الاستعادة</button>
            </form></td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </section>
    <?php if (can('backups.restore')): ?>
    <section class="glass card"><h2><?= icon('cloud-upload') ?>رفع نسخة احتياطية للاستعادة</h2>
      <div class="uploader" data-uploader data-purpose="backup" data-accept=".gz,.sql,.zip" data-reload="1">
        <label class="drop"><?= icon('archive', 'big') ?><b>اسحب ملف النسخة هنا</b><small>.sql.gz أو .sql أو .zip — يُرفع على أجزاء ويمكن استكماله</small><input type="file" accept=".gz,.sql,.zip" data-input></label>
        <div data-queue></div>
      </div>
    </section>
    <?php endif; ?>
  </div>
  <div>
    <section class="glass card"><h2><?= icon('plus') ?>إنشاء نسخة الآن</h2>
      <form method="post" action="<?= e(url('/admin/backups')) ?>"><?= csrf_field() ?>
        <input type="hidden" name="include_media" value="0"><label class="check"><input type="checkbox" name="include_media" value="1">تضمين الصور والوسائط (ملف ZIP)</label>
        <p class="muted" style="font-size:var(--fs-xs)">ملفات الألعاب الكبيرة لا تدخل في النسخة؛ انسخ مجلد التخزين بأداة مثل rsync.</p>
        <button class="btn btn-primary btn-block" type="submit"><?= icon('archive') ?>إنشاء نسخة احتياطية</button></form>
    </section>
    <form method="post" action="<?= e(url('/admin/backups/settings')) ?>" class="glass card"><?= csrf_field() ?>
      <h2><?= icon('calendar-clock') ?>النسخ الدورية</h2>
      <label class="field"><span>التكرار</span><select name="backups_frequency"><?php foreach (['off' => 'متوقف', 'daily' => 'يومياً', 'weekly' => 'أسبوعياً'] as $k => $l): ?><option value="<?= $k ?>" <?= setting('backups.frequency', 'daily') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <label class="field" style="margin-top:10px"><span>عدد النسخ المحفوظة</span><input type="number" name="backups_keep" value="<?= (int) setting('backups.keep', 7) ?>" min="1" max="365"></label>
      <input type="hidden" name="backups_include_media" value="0"><label class="switch"><span>تضمين الوسائط في النسخ الدورية</span><input type="checkbox" name="backups_include_media" value="1" <?= setting('backups.include_media', false) ? 'checked' : '' ?>></label>
      <p class="muted" style="font-size:var(--fs-xs)">تعمل عبر <span class="code-inline">bin/cron.php</span> المجدول كل دقيقة. آخر نسخة: <?= setting('backups.last_run') ? e(time_ago((string) setting('backups.last_run'))) : '—' ?></p>
      <button class="btn btn-primary" type="submit"><?= icon('save') ?>حفظ</button>
    </form>
  </div>
</div>
