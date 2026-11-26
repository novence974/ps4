<?php /** @var array $platform @var array $result @var array $stats */ $p = $platform; ?>
<section class="page-hero">
  <div class="hero-bg" aria-hidden="true"><?php if ($p['image_path']): ?><img src="<?= e(media_url($p['image_path'], 'lg')) ?>" alt=""><?php endif; ?></div>
  <div class="wrap">
    <div class="game-head" style="align-items:center">
      <div class="slab logo-plate" style="--pc: <?= e($p['accent_color']) ?>; width:clamp(150px,16vw,210px); aspect-ratio:1; min-height:auto; justify-content:center; align-items:center">
        <?php if ($p['logo_path']): ?><img class="logo" src="<?= e(platform_logo($p)) ?>" alt="شعار <?= e($p['name']) ?>" style="width:70%;height:70%"><?php else: ?><span class="glyph"><?= e($p['short_name']) ?></span><?php endif; ?>
      </div>
      <div>
        <div class="chips"><span class="badge" style="--pc: <?= e($p['accent_color']) ?>"><?= e($p['short_name']) ?></span><span class="chip"><?= icon('calendar') ?><?= (int) $p['release_year'] ?></span><span class="chip"><?= icon('layers') ?>الجيل <?= (int) $p['generation'] ?></span></div>
        <h1 class="game-title"><?= e($p['name']) ?></h1>
        <p class="muted" style="max-width:70ch"><?= e($p['description']) ?></p>
        <div class="stats-row" style="margin-inline-start:0">
          <div class="stat"><b><?= number_format((int) ($stats['total'] ?? 0)) ?></b><span><?= ar_noun((int) ($stats['total'] ?? 0), 'لعبة', 'ألعاب') ?></span></div>
          <div class="stat"><b><?= number_format((int) ($stats['available'] ?? 0)) ?></b><span>ملفاتها متوفرة</span></div>
          <div class="stat"><b><?= $stats['avg_rating'] ? number_format((float) $stats['avg_rating'], 1) : '—' ?></b><span>متوسط التقييم</span></div>
          <div class="stat"><b class="ltr"><?= $stats['first_year'] ? (int) $stats['first_year'] . '–' . (int) $stats['last_year'] : '—' ?></b><span>سنوات الإصدار</span></div>
        </div>
      </div>
    </div>
  </div>
</section>

<div class="wrap">
  <div class="grid-3" style="margin-top:10px">
    <div class="glass panel"><h3><?= icon('disc') ?> الصيغ المدعومة</h3><p class="muted" style="margin:0"><?= e($p['supported_formats'] ?: '—') ?></p></div>
    <div class="glass panel"><h3><?= icon('compare') ?> التوافق</h3><p class="muted" style="margin:0"><?= e($p['compatibility_notes'] ?: '—') ?></p></div>
    <div class="glass panel"><h3><?= icon('shield') ?> طرق التثبيت المسموح بها</h3><p class="muted" style="margin:0"><?= e($p['install_notes'] ?: '—') ?></p></div>
  </div>

  <div class="browse">
    <?= partial('filters', ['filters' => $filters, 'categories' => $categories, 'facets' => $facets, 'fixed' => ['platform'], 'action' => url('platform/' . $p['slug'])]) ?>
    <?= partial('results', ['result' => $result, 'filters' => $filters, 'favIds' => $favIds, 'fixed' => ['platform']]) ?>
  </div>
</div>
