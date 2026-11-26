<?php
/** @var array $home @var array $hero @var array $platforms @var array $categories @var array $updates @var array $favIds */
$first = $hero[0] ?? null;
?>
<?php if ($first): ?>
<section class="stage" data-stage aria-label="ألعاب مختارة">
  <div class="stage-bg" aria-hidden="true">
    <?php foreach ($hero as $i => $g): ?>
      <img src="<?= e(!empty($g['banner_path']) ? media_url($g['banner_path'], 'sm') : cover_url($g, 'md')) ?>" alt="" class="<?= $i === 0 ? 'on' : '' ?>" data-bg="<?= $i ?>" <?= $i ? 'loading="lazy"' : 'fetchpriority="high"' ?>>
    <?php endforeach; ?>
  </div>
  <div class="wrap">
    <div class="stage-main">
      <div class="stage-copy stage-swap" data-stage-copy>
        <div class="stage-kicker">
          <span class="badge" data-f="platform" style="--pc: <?= e($first['platform_color']) ?>"><?= e($first['platform_short']) ?></span>
          <span class="chip" data-f="year"><?= icon('calendar') ?><span><?= e((string) ($first['release_year'] ?? '—')) ?></span></span>
          <span class="chip" data-f="genre"><?= icon('tag') ?><span><?= e($first['categories'][0]['name'] ?? 'لعبة') ?></span></span>
        </div>
        <h1 class="stage-title" data-f="title"><?= e($first['title']) ?></h1>
        <p class="stage-desc" data-f="meta"><?= e(trim(($first['developer'] ?? '') . ($first['publisher'] && $first['publisher'] !== $first['developer'] ? ' / ' . $first['publisher'] : ''))) ?></p>
        <div class="row">
          <a class="btn btn-primary" href="<?= e(url('game/' . $first['slug'])) ?>" data-f="link"><?= icon('gamepad') ?>تفاصيل اللعبة</a>
          <a class="btn" href="<?= e(url('/games')) ?>"><?= icon('grid') ?>تصفح المكتبة</a>
          <span data-f="rating"><?= rating_html($first['rating'] !== null ? (float) $first['rating'] : null) ?></span>
        </div>
      </div>
      <a class="stage-cover" href="<?= e(url('game/' . $first['slug'])) ?>" data-f="cover-link" tabindex="-1" aria-hidden="true">
        <img src="<?= e(cover_url($first, 'md')) ?>" alt="" data-f="cover" width="600" height="900">
      </a>
    </div>
    <div class="shelf" role="listbox" aria-label="اختر لعبة لعرضها">
      <?php foreach ($hero as $i => $g): ?>
        <button class="shelf-tile<?= $i === 0 ? ' on' : '' ?>" type="button" role="option" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
          style="--pc: <?= e($g['platform_color']) ?>" data-shelf="<?= $i ?>" aria-label="<?= e($g['title']) ?>"
          data-game='<?= e(json_encode([
              'title' => $g['title'], 'platform' => $g['platform_short'], 'color' => $g['platform_color'], 'year' => $g['release_year'] ?? '—',
              'genre' => $g['categories'][0]['name'] ?? 'لعبة', 'meta' => trim(($g['developer'] ?? '') . ($g['publisher'] && $g['publisher'] !== $g['developer'] ? ' / ' . $g['publisher'] : '')),
              'url' => url('game/' . $g['slug']), 'cover' => cover_url($g, 'md'), 'rating' => rating_html($g['rating'] !== null ? (float) $g['rating'] : null),
          ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
          <img src="<?= e(cover_url($g, 'sm')) ?>" alt="" loading="lazy" width="300" height="450">
        </button>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php else: ?>
<section class="wrap">
  <div class="glass panel" style="margin-top:32px">
    <?= partial('empty', ['icon' => 'library', 'heading' => 'المكتبة جاهزة لاستقبال الألعاب', 'text' => 'لم تُنشر أي لعبة بعد. أضف أول لعبة من لوحة التحكم أو استورد قائمة كاملة بصيغة CSV أو JSON.',
        'action' => can('games.create') ? ['/admin/games/create', 'إضافة لعبة'] : null]) ?>
  </div>
</section>
<?php endif; ?>

<div class="wrap">
  <section class="section" aria-labelledby="h-platforms">
    <div class="section-head"><h2 id="h-platforms">الأجهزة</h2><a class="more" href="<?= e(url('/platforms')) ?>">كل الأجهزة <?= icon('chev-left') ?></a></div>
    <div class="slabs">
      <?php foreach ($platforms as $p): ?>
        <a class="slab" data-gen="<?= e($p['slug']) ?>" href="<?= e(url('platform/' . $p['slug'])) ?>" style="--pc: <?= e($p['accent_color']) ?>">
          <span class="glyph"><?= e($p['short_name']) ?></span>
          <span><span class="name"><?= e($p['name']) ?></span><br><span class="count"><?= number_format((int) $p['games_count']) ?> <?= ar_noun((int) $p['games_count'], 'لعبة', 'ألعاب') ?></span></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <?= partial('rail', ['id' => 'h-recent', 'heading' => 'أضيفت حديثاً', 'more' => '/games?sort=newest', 'games' => $home['recent'], 'favIds' => $favIds]) ?>
  <?= partial('rail', ['id' => 'h-top', 'heading' => 'الأعلى تقييماً', 'more' => '/games?sort=rating', 'games' => $home['top_rated'], 'favIds' => $favIds]) ?>
  <?= partial('rail', ['id' => 'h-popular', 'heading' => 'الأكثر تحميلاً', 'more' => '/games?sort=downloads', 'games' => $home['popular'], 'favIds' => $favIds]) ?>

  <section class="section" aria-labelledby="h-cats">
    <div class="section-head"><h2 id="h-cats">التصنيفات</h2><a class="more" href="<?= e(url('/categories')) ?>">كل التصنيفات <?= icon('chev-left') ?></a></div>
    <div class="cat-grid">
      <?php foreach ($categories as $c): ?>
        <a class="cat" href="<?= e(url('category/' . $c['slug'])) ?>" style="--cc: <?= e($c['color']) ?>">
          <span class="ico"><?= icon('cat-' . ($c['icon'] ?: 'bolt')) ?></span>
          <span><b><?= e($c['name']) ?></b><small><?= number_format((int) $c['games_count']) ?> <?= ar_noun((int) $c['games_count'], 'لعبة', 'ألعاب') ?></small></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if ($updates): ?>
  <section class="section" aria-labelledby="h-updates">
    <div class="section-head"><h2 id="h-updates">آخر التحديثات</h2><a class="more" href="<?= e(url('/updates')) ?>">كل التحديثات <?= icon('chev-left') ?></a></div>
    <div class="grid-2">
      <?php foreach ($updates as $up): ?>
        <article class="feed-item">
          <?php if ($up['game_cover']): ?><img class="thumb" src="<?= e(media_url($up['game_cover'], 'sm')) ?>" alt="" loading="lazy"><?php else: ?><span class="ico"><?= icon('megaphone') ?></span><?php endif; ?>
          <div>
            <span class="tag tag-info"><?= e(update_type_names()[$up['type']] ?? $up['type']) ?></span>
            <h3><?php if ($up['game_slug']): ?><a href="<?= e(url('game/' . $up['game_slug'])) ?>"><?= e($up['title']) ?></a><?php else: ?><?= e($up['title']) ?><?php endif; ?></h3>
            <span class="when"><?= e(time_ago($up['published_at'] ?? $up['created_at'])) ?></span>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</div>
