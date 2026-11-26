<div class="wrap">
  <?= App\Core\View::file('account/header', []) ?>
  <h2>سجل التنزيلات <span class="muted" style="font-size:var(--fs-sm)">(<?= (int) $result['total'] ?>)</span></h2>
  <?php if (!$result['items']): ?>
    <div class="glass"><?= partial('empty', ['icon' => 'download', 'heading' => 'لا توجد تنزيلات بعد', 'action' => ['/games', 'تصفح المكتبة']]) ?></div>
  <?php else: ?>
  <div class="glass table-wrap">
    <table class="table">
      <thead><tr><th>اللعبة</th><th>الملف</th><th>النوع</th><th>الحجم</th><th>التاريخ</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($result['items'] as $d): ?>
        <tr>
          <td><a href="<?= e(url('game/' . $d['slug'])) ?>"><b><?= e($d['title']) ?></b></a> <span class="tag"><?= e($d['platform_short']) ?></span></td>
          <td class="mono" style="max-width:280px; overflow-wrap:anywhere"><?= e($d['original_name'] ?? 'ملف محذوف') ?></td>
          <td><?= e(file_type_names()[$d['file_type'] ?? ''] ?? '—') ?></td>
          <td class="ltr"><?= e(human_bytes($d['size_bytes'] !== null ? (int) $d['size_bytes'] : null)) ?></td>
          <td><?= e(fmt_date($d['created_at'], 'Y/m/d H:i')) ?></td>
          <td><?php if ($d['file_id']): ?><a class="btn btn-xs" href="<?= e(url('download/' . (int) $d['file_id'])) ?>" data-download="<?= (int) $d['file_id'] ?>" data-name="<?= e((string) $d['original_name']) ?>" data-size="<?= (int) $d['size_bytes'] ?>" data-sha="<?= e((string) $d['sha256']) ?>"><?= icon('download') ?>تنزيل مجدداً</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= partial('pagination', ['result' => $result]) ?>
  <?php endif; ?>
</div>
