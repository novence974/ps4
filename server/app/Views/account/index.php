<div class="wrap">
  <?= App\Core\View::file('account/header', ['stats' => $stats]) ?>
  <div class="grid-2">
    <section class="glass panel">
      <div class="section-head"><h2>المفضلة</h2><a class="more" href="<?= e(url('/account/favorites')) ?>">الكل <?= icon('chev-left') ?></a></div>
      <?php if ($favorites): ?><div class="cards" style="grid-template-columns:repeat(3,minmax(0,1fr))"><?= partial('game-grid', ['games' => $favorites, 'favIds' => $favIds]) ?></div>
      <?php else: ?><?= partial('empty', ['icon' => 'heart', 'heading' => 'لا توجد ألعاب في المفضلة', 'text' => 'اضغط على القلب في أي لعبة لتصلك تحديثاتها.', 'action' => ['/games', 'تصفح المكتبة']]) ?><?php endif; ?>
    </section>
    <div class="stack">
      <section class="glass panel">
        <div class="section-head"><h2>آخر التنزيلات</h2><a class="more" href="<?= e(url('/account/downloads')) ?>">الكل <?= icon('chev-left') ?></a></div>
        <?php if ($downloads): ?>
        <ul class="list">
          <?php foreach ($downloads as $d): ?>
            <li><img class="thumb" src="<?= e(cover_url($d, 'sm')) ?>" alt="" loading="lazy"><div class="grow"><a href="<?= e(url('game/' . $d['slug'])) ?>"><b><?= e($d['title']) ?></b></a><small><?= e($d['original_name'] ?? 'ملف محذوف') ?> — <?= e(time_ago($d['created_at'])) ?></small></div></li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?><p class="muted">لم تنزّل أي ملف بعد.</p><?php endif; ?>
      </section>
      <section class="glass panel">
        <div class="section-head"><h2>تحديثات ألعابك</h2><a class="more" href="<?= e(url('/account/updates')) ?>">الكل <?= icon('chev-left') ?></a></div>
        <?php if ($updates): ?>
        <ul class="list"><?php foreach ($updates as $up): ?><li><span class="notif"><span class="ico"><?= icon('refresh') ?></span></span><div class="grow"><b><?= e($up['title']) ?></b><small><?= e(time_ago($up['published_at'] ?? $up['created_at'])) ?></small></div></li><?php endforeach; ?></ul>
        <?php else: ?><p class="muted">ستظهر هنا تحديثات الألعاب التي في مفضلتك.</p><?php endif; ?>
      </section>
    </div>
  </div>
  <?php if ($history): ?>
  <section class="section">
    <div class="section-head"><h2>شاهدتها مؤخراً</h2><a class="more" href="<?= e(url('/account/history')) ?>">السجل الكامل <?= icon('chev-left') ?></a></div>
    <div class="rail"><?= partial('game-grid', ['games' => $history, 'favIds' => $favIds]) ?></div>
  </section>
  <?php endif; ?>
</div>
