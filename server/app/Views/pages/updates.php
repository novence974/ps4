<div class="wrap">
  <h1 class="page-title">التحديثات</h1>
  <p class="page-sub">كل ما أضيف أو تغيّر في المكتبة: ألعاب جديدة، إصدارات وتصحيحات، وإعلانات الصيانة.</p>
  <div class="chips" style="margin-bottom:18px">
    <a class="chip<?= $type === '' ? ' on' : '' ?>" href="<?= e(url('/updates')) ?>" <?= $type === '' ? 'aria-current="true"' : '' ?>>الكل</a>
    <?php foreach (update_type_names() as $k => $l): ?><a class="chip" href="<?= e(url('/updates?type=' . $k)) ?>" <?= $type === $k ? 'aria-current="true" style="border-color:var(--violet)"' : '' ?>><?= e($l) ?></a><?php endforeach; ?>
  </div>
  <?php if (!$result['items']): ?>
    <div class="glass"><?= partial('empty', ['icon' => 'sparkles', 'heading' => 'لا توجد تحديثات بعد']) ?></div>
  <?php else: ?>
  <div class="feed">
    <?php foreach ($result['items'] as $up): ?>
      <article class="feed-item">
        <?php if ($up['game_cover']): ?><img class="thumb" src="<?= e(media_url($up['game_cover'], 'sm')) ?>" alt="" loading="lazy"><?php else: ?><span class="ico"><?= icon($up['type'] === 'maintenance' ? 'cog' : 'megaphone') ?></span><?php endif; ?>
        <div>
          <div class="row" style="gap:8px"><span class="tag tag-info"><?= e(update_type_names()[$up['type']] ?? $up['type']) ?></span><?php if ($up['platform_short']): ?><span class="tag"><?= e($up['platform_short']) ?></span><?php endif; ?><?php if ($up['version_name']): ?><span class="tag ltr">v<?= e($up['version_name']) ?></span><?php endif; ?></div>
          <h3><?php if ($up['game_slug']): ?><a href="<?= e(url('game/' . $up['game_slug'])) ?>"><?= e($up['title']) ?></a><?php else: ?><?= e($up['title']) ?><?php endif; ?></h3>
          <?php if ($up['body']): ?><p class="text"><?= e($up['body']) ?></p><?php endif; ?>
          <span class="when"><?= e(fmt_date($up['published_at'] ?? $up['created_at'], 'Y/m/d H:i')) ?> — <?= e(time_ago($up['published_at'] ?? $up['created_at'])) ?></span>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <?= partial('pagination', ['result' => $result]) ?>
  <?php endif; ?>
</div>
