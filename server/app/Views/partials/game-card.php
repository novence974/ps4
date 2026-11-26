<?php
/** @var array $g */
$isFav = in_array((int) $g['id'], $favIds ?? [], true);
$pc = $g['platform_color'] ?? '#8b7cff';
?>
<article class="gcard<?= empty($g['is_published']) ? ' hidden-game' : '' ?>" style="--pc: <?= e($pc) ?>">
  <div class="cover">
    <img src="<?= e(cover_url($g, 'sm')) ?>" alt="" loading="lazy" decoding="async" width="300" height="450">
    <span class="badge" style="--pc: <?= e($pc) ?>"><?= e($g['platform_short']) ?></span>
    <?php if ($g['rating'] !== null): ?><span class="score"><i></i><?= e(number_format((float) $g['rating'], 1)) ?></span><?php endif; ?>
    <span class="avail <?= e($g['files_status']) ?>" title="<?= e(files_status_names()[$g['files_status']] ?? '') ?>"></span>
  </div>
  <a class="stretch" href="<?= e(url('game/' . $g['slug'])) ?>" aria-label="<?= e($g['title'] . ' — ' . $g['platform_short']) ?>"></a>
  <?php if (auth_user()): ?>
    <form method="post" action="<?= e(url('favorites/' . (int) $g['id'])) ?>" data-fav-form>
      <?= csrf_field() ?>
      <button class="fav<?= $isFav ? ' on' : '' ?>" type="submit" aria-pressed="<?= $isFav ? 'true' : 'false' ?>" aria-label="<?= $isFav ? 'إزالة من المفضلة' : 'إضافة إلى المفضلة' ?>"><?= icon('heart') ?></button>
    </form>
  <?php else: ?>
    <a class="fav" href="<?= e(url('login')) ?>" aria-label="سجّل الدخول لإضافة اللعبة إلى المفضلة"><?= icon('heart') ?></a>
  <?php endif; ?>
  <div class="body">
    <h3><?= e($g['title']) ?></h3>
    <div class="meta">
      <?php if ($g['release_year']): ?><span><?= (int) $g['release_year'] ?></span><?php endif; ?>
      <?php if (!empty($g['categories'][0])): ?><span><?= e($g['categories'][0]['name']) ?></span><?php endif; ?>
      <?php if ($g['size_bytes']): ?><span class="ltr"><?= e(human_bytes((int) $g['size_bytes'])) ?></span><?php endif; ?>
    </div>
  </div>
</article>
