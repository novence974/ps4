<?php $ov = $o; ?>
<div class="tiles">
  <div class="tile"><span class="ico"><?= icon('files') ?></span><b class="ltr"><?= e(human_bytes((int) array_sum(array_column($ov['by_type'], 'bytes')))) ?></b><span>ملفات الألعاب</span></div>
  <div class="tile t-azure"><span class="ico"><?= icon('image') ?></span><b class="ltr"><?= e(human_bytes($ov['media_bytes'])) ?></b><span>الوسائط العامة (أغلفة وصور)</span></div>
  <div class="tile t-warn"><span class="ico"><?= icon('layers') ?></span><b class="ltr"><?= e(human_bytes($ov['chunks_bytes'])) ?></b><span>أجزاء رفع غير مكتملة</span></div>
  <div class="tile t-rose"><span class="ico"><?= icon('archive') ?></span><b class="ltr"><?= e(human_bytes($ov['backups_bytes'])) ?></b><span>النسخ الاحتياطية</span></div>
  <div class="tile"><span class="ico"><?= icon('terminal') ?></span><b class="ltr"><?= e(human_bytes($ov['logs_bytes'] + $ov['tmp_bytes'])) ?></b><span>السجلات والملفات المؤقتة</span></div>
</div>
<div class="dash">
  <div>
    <section class="glass card"><h2><?= icon('drive') ?>أقراص التخزين</h2>
      <?php foreach ($ov['disks'] as $d): $pct = $d['total'] ? ($d['total'] - $d['free']) / $d['total'] * 100 : 0; ?>
        <div style="margin-bottom:14px">
          <div class="row" style="justify-content:space-between"><b class="ltr"><?= e($d['name']) ?></b><span><?= $d['writable'] ? '<span class="tag tag-ok">قابل للكتابة</span>' : '<span class="tag tag-bad">غير قابل للكتابة</span>' ?></span></div>
          <div class="meter <?= $pct > 90 ? 'bad' : ($pct > 75 ? 'warn' : '') ?>" style="margin:8px 0"><i style="width: <?= round($pct, 1) ?>%"></i></div>
          <small class="muted">المستخدم <?= round($pct, 1) ?>% — المتاح <span class="ltr"><?= e(human_bytes($d['free'])) ?></span> من <span class="ltr"><?= e(human_bytes($d['total'])) ?></span> — المكتبة <?= e(human_bytes($d['library_bytes'])) ?> (<?= (int) $d['library_files'] ?> ملف)</small>
        </div>
      <?php endforeach; ?>
      <p class="muted" style="font-size:var(--fs-xs)">لإضافة تخزين خارجي (قرص ثانٍ، NAS، أو تخزين سحابي مركّب عبر rclone/s3fs) أضف مساراً في <span class="code-inline">storage.disks</span> داخل config.php ثم اختره كقرص افتراضي من صفحة الأمان.</p>
    </section>
    <section class="glass card"><h2><?= icon('chart') ?>الاستخدام حسب نوع الملف</h2>
      <?php if (!$ov['by_type']): ?><p class="muted">لا توجد ملفات.</p><?php else: ?>
      <div class="table-wrap"><table class="table"><thead><tr><th>النوع</th><th>العدد</th><th>الحجم</th></tr></thead><tbody>
        <?php foreach ($ov['by_type'] as $t): ?><tr><td><?= e(file_type_names()[$t['file_type']] ?? $t['file_type']) ?></td><td><?= (int) $t['files'] ?></td><td class="ltr"><?= e(human_bytes((int) $t['bytes'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </section>
    <section class="glass card"><h2><?= icon('drive') ?>أكبر الملفات</h2>
      <?php if (!$ov['largest']): ?><p class="muted">لا توجد ملفات.</p><?php else: ?>
      <ul class="list"><?php foreach ($ov['largest'] as $l): ?><li><div class="grow"><b class="ltr"><?= e($l['original_name']) ?></b><small><a href="<?= e(url('/admin/games/' . (int) $l['game_id'] . '/edit?tab=files')) ?>"><?= e($l['game_title']) ?></a></small></div><b class="ltr"><?= e(human_bytes((int) $l['size_bytes'])) ?></b></li><?php endforeach; ?></ul><?php endif; ?>
    </section>
  </div>
  <div>
    <section class="glass card"><h2><?= icon('wand') ?>صيانة التخزين</h2>
      <form method="post" action="<?= e(url('/admin/storage/cleanup')) ?>" data-confirm="حذف كل أجزاء الرفع غير المكتملة؟ لن يمكن استكمالها بعد ذلك." style="margin-bottom:10px"><?= csrf_field() ?><button class="btn btn-block" type="submit"><?= icon('trash') ?>تنظيف أجزاء الرفع المتروكة</button></form>
      <form method="post" action="<?= e(url('/admin/storage/cache')) ?>" style="margin-bottom:10px"><?= csrf_field() ?><button class="btn btn-block" type="submit"><?= icon('refresh') ?>مسح الكاش</button></form>
      <a class="btn btn-block" href="?orphans=1"><?= icon('scan') ?>فحص الملفات اليتيمة</a>
      <p class="muted" style="font-size:var(--fs-xs); margin-top:10px">الملف اليتيم موجود على القرص بلا سجل في قاعدة البيانات (نسخ يدوي أو انقطاع أثناء المعالجة).</p>
    </section>
    <?php if ($orphans !== null): ?>
    <section class="glass card"><h2><?= icon('scan') ?>الملفات اليتيمة (<?= count($orphans) ?>)</h2>
      <?php if (!$orphans): ?><p class="muted">لا توجد ملفات يتيمة ✓</p><?php else: ?>
        <ul class="list"><?php foreach (array_slice($orphans, 0, 50) as $x): ?><li><div class="grow"><b class="ltr mono" style="overflow-wrap:anywhere"><?= e($x['disk'] . ':' . $x['path']) ?></b><small><?= e($x['modified']) ?></small></div><span class="ltr"><?= e(human_bytes($x['size'])) ?></span></li><?php endforeach; ?></ul>
        <form method="post" action="<?= e(url('/admin/storage/orphans')) ?>" data-confirm="حذف كل الملفات اليتيمة نهائياً؟"><?= csrf_field() ?><button class="btn btn-danger btn-block" type="submit"><?= icon('trash') ?>حذف الملفات اليتيمة</button></form>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </div>
</div>
