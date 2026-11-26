<?php $statusTag = ['uploading' => 'tag-info', 'processing' => 'tag-warn', 'completed' => 'tag-ok', 'failed' => 'tag-bad', 'cancelled' => '', 'expired' => '']; $statusName = ['uploading' => 'قيد الرفع', 'processing' => 'قيد المعالجة', 'completed' => 'مكتمل', 'failed' => 'فشل', 'cancelled' => 'ملغى', 'expired' => 'منتهي']; ?>
<section class="glass card">
  <h2><?= icon('cloud-upload') ?>رفع ملفات الألعاب</h2>
  <div class="uploader" data-uploader data-purpose="game_file" data-multiple="1" data-accept="<?= e('.' . implode(',.', $allowedExt)) ?>">
    <div class="inline-form">
      <label class="field" style="grid-column: span 2"><span>اللعبة *</span>
        <input type="search" placeholder="اكتب لتصفية القائمة…" data-filter-select="#up-game" aria-label="تصفية الألعاب">
        <select id="up-game" data-meta="game_id" required style="margin-top:6px"><option value="">اختر اللعبة</option><?php foreach ($games as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['title']) ?> — <?= e($g['short_name']) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>نوع الملف</span><select data-meta="file_type"><?php foreach (file_type_names() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>صلاحية التنزيل</span><select data-meta="access_level"><?php foreach (access_names() as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'registered' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>المنطقة</span><select data-meta="region"><option value="">—</option><?php foreach (region_names() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>رقم الإصدار</span><input type="text" data-meta="version_label" maxlength="32" dir="ltr" placeholder="1.00"></label>
    </div>
    <label class="drop"><?= icon('cloud-upload', 'big') ?><b>اسحب الملفات هنا أو اضغط للاختيار</b>
      <small>حجم الجزء <?= e(human_bytes($chunkSize)) ?> — حتى <?= (int) setting('uploads.max_file_gb', 200) ?>GB للملف — إيقاف واستكمال، واستئناف تلقائي بعد انقطاع الشبكة، وتحقق SHA-256 لكل جزء وللملف كاملاً</small>
      <input type="file" multiple data-input></label>
    <div data-queue></div>
  </div>
  <p class="muted" style="font-size:var(--fs-xs); margin-top:12px">طابور المعالجة: <?= (int) $queue['pending'] ?> بانتظار، <?= (int) $queue['running'] ?> تعمل، <?= (int) $queue['failed'] ?> فشلت. الملفات الأكبر من <?= (int) setting('uploads.sync_assemble_limit_mb', 2048) ?>MB تُجمَّع في الخلفية عبر <span class="code-inline">bin/worker.php</span>.</p>
</section>
<section class="glass card">
  <h2><?= icon('list') ?>عمليات الرفع<span class="spacer"></span>
    <form method="get" class="row"><select name="status" data-autosubmit aria-label="الحالة"><option value="">كل الحالات</option><?php foreach ($statusName as $k => $l): ?><option value="<?= $k ?>" <?= (App\Core\Request::$current?->query('status')) === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></form></h2>
  <div class="table-wrap"><table class="table"><thead><tr><th>الملف</th><th>النوع</th><th>اللعبة</th><th>التقدم</th><th>الحالة</th><th>المستخدم</th><th>التحديث</th><th></th></tr></thead><tbody>
    <?php foreach ($result['items'] as $u): $pct = $u['size_bytes'] ? $u['received_bytes'] / $u['size_bytes'] * 100 : 0; ?>
      <tr>
        <td><b class="ltr" style="overflow-wrap:anywhere"><?= e($u['original_name']) ?></b><br><small class="muted"><?= e(human_bytes((int) $u['size_bytes'])) ?></small><?php if ($u['sha256']): ?><br><small class="mono faint"><?= e(substr($u['sha256'], 0, 20)) ?>…</small><?php endif; ?></td>
        <td><?= e($u['purpose']) ?></td>
        <td><?= e($u['game_title'] ?? '—') ?></td>
        <td style="min-width:140px"><div class="meter"><i style="width: <?= round($pct, 1) ?>%"></i></div><small class="muted"><?= round($pct, 1) ?>% (<?= (int) $u['received_chunks'] ?>/<?= (int) $u['total_chunks'] ?>)</small></td>
        <td><span class="tag <?= $statusTag[$u['status']] ?? '' ?>"><?= e($statusName[$u['status']] ?? $u['status']) ?></span><?php if ($u['error']): ?><br><small class="muted"><?= e($u['error']) ?></small><?php endif; ?></td>
        <td><?= e($u['username'] ?? '—') ?></td>
        <td class="muted nowrap"><?= e(time_ago($u['updated_at'])) ?></td>
        <td><?php if ($u['status'] === 'uploading'): ?><button class="btn btn-xs btn-danger" type="button" data-cancel-upload="<?= e($u['uuid']) ?>"><?= icon('x') ?>إلغاء</button><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php if (!$result['items']): ?><p class="muted">لا توجد عمليات رفع.</p><?php endif; ?>
  <?= partial('pagination', ['result' => $result]) ?>
</section>
