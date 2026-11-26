<div class="tiles">
  <div class="tile t-aqua"><span class="ico"><?= icon('download') ?></span><b><?= number_format($stats['downloads_total']) ?></b><span>إجمالي التنزيلات</span></div>
  <div class="tile"><span class="ico"><?= icon('clock') ?></span><b><?= number_format($stats['downloads_24h']) ?></b><span>آخر 24 ساعة</span></div>
</div>
<div class="dash">
  <section>
    <div class="toolbar"><form method="get"><input type="search" name="q" value="<?= e((string) (App\Core\Request::$current?->query('q') ?? '')) ?>" placeholder="مستخدم، لعبة أو عنوان IP" aria-label="بحث"><button class="btn btn-sm" type="submit"><?= icon('search') ?>بحث</button></form></div>
    <div class="glass table-wrap"><table class="table"><thead><tr><th>الوقت</th><th>المستخدم</th><th>اللعبة</th><th>الملف</th><th>IP</th></tr></thead><tbody>
      <?php foreach ($result['items'] as $d): ?><tr><td class="nowrap"><?= e(fmt_date($d['created_at'], 'Y/m/d H:i')) ?></td><td><?= e($d['username'] ?? 'زائر') ?></td><td><a href="<?= e(url('game/' . $d['slug'])) ?>"><?= e($d['title']) ?></a></td><td class="ltr" style="overflow-wrap:anywhere"><?= e($d['original_name'] ?? '—') ?> <small class="muted"><?= e(human_bytes($d['size_bytes'] !== null ? (int) $d['size_bytes'] : null)) ?></small></td><td class="mono"><?= e($d['ip'] ?? '') ?></td></tr><?php endforeach; ?>
    </tbody></table><?php if (!$result['items']): ?><?= partial('empty', ['icon' => 'download', 'heading' => 'لا توجد تنزيلات بعد']) ?><?php endif; ?></div>
    <?= partial('pagination', ['result' => $result]) ?>
  </section>
  <section class="glass card" style="align-self:start"><h2><?= icon('flame') ?>الأكثر تنزيلاً (30 يوماً)</h2>
    <?php if (!$top): ?><p class="muted">لا توجد بيانات.</p><?php else: $max = max(array_column($top, 'c')); ?>
      <div class="hbars"><?php foreach ($top as $t): ?><div class="hbar" style="grid-template-columns:1fr 1fr auto"><span><?= e($t['title']) ?></span><span class="track"><i style="width: <?= round($t['c'] / $max * 100) ?>%"></i></span><b><?= (int) $t['c'] ?></b></div><?php endforeach; ?></div>
    <?php endif; ?></section>
</div>
