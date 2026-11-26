<?php if (!$items): ?>
  <div class="empty" style="padding:22px"><?= icon('bell') ?><p>لا توجد إشعارات بعد</p></div>
<?php else: ?>
<ul class="list">
  <?php foreach ($items as $n): ?>
    <li class="notif<?= empty($n['is_read']) ? ' unread' : '' ?>">
      <span class="ico"><?= icon($n['type'] === 'game_update' ? 'refresh' : ($n['type'] === 'welcome' ? 'sparkles' : 'megaphone')) ?></span>
      <div class="grow">
        <?php if ($n['link']): ?><a href="<?= e(str_starts_with($n['link'], '/') ? url($n['link']) : $n['link']) ?>"><b><?= e($n['title']) ?></b></a><?php else: ?><b><?= e($n['title']) ?></b><?php endif; ?>
        <?php if ($n['body'] && empty($compact)): ?><small style="display:block"><?= e($n['body']) ?></small><?php endif; ?>
        <small><?= e(time_ago($n['created_at'])) ?></small>
      </div>
    </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
