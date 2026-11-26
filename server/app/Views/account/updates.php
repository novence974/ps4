<div class="wrap">
  <?= App\Core\View::file('account/header', []) ?>
  <h2>سجل التحديثات</h2>
  <p class="muted">تحديثات وإصدارات الألعاب الموجودة في مفضلتك، مع إعلانات المكتبة العامة.</p>
  <?php if (!$result['items']): ?>
    <div class="glass"><?= partial('empty', ['icon' => 'refresh', 'heading' => 'لا توجد تحديثات لألعابك بعد', 'text' => 'أضف ألعاباً إلى المفضلة لتتابع إصداراتها.']) ?></div>
  <?php else: ?>
  <div class="feed">
    <?php foreach ($result['items'] as $up): ?>
      <article class="feed-item">
        <?php if ($up['game_cover']): ?><img class="thumb" src="<?= e(media_url($up['game_cover'], 'sm')) ?>" alt="" loading="lazy"><?php else: ?><span class="ico"><?= icon('megaphone') ?></span><?php endif; ?>
        <div><span class="tag tag-info"><?= e(update_type_names()[$up['type']] ?? '') ?></span>
          <h3><?php if ($up['game_slug']): ?><a href="<?= e(url('game/' . $up['game_slug'])) ?>"><?= e($up['title']) ?></a><?php else: ?><?= e($up['title']) ?><?php endif; ?></h3>
          <?php if ($up['body']): ?><p class="text"><?= e($up['body']) ?></p><?php endif; ?>
          <span class="when"><?= e(time_ago($up['published_at'] ?? $up['created_at'])) ?></span></div>
      </article>
    <?php endforeach; ?>
  </div>
  <?= partial('pagination', ['result' => $result]) ?>
  <?php endif; ?>
</div>
