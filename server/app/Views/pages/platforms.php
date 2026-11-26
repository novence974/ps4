<div class="wrap">
  <h1 class="page-title">أجهزة PlayStation</h1>
  <p class="page-sub">خمسة أجيال في مكان واحد. لكل جهاز صفحة بمكتبته ومعلومات الصيغ المدعومة والتوافق.</p>
  <div class="grid">
    <?php foreach ($platforms as $p): ?>
      <a class="glass panel sheen plat-row" data-gen="<?= e($p['slug']) ?>" href="<?= e(url('platform/' . $p['slug'])) ?>" style="--pc: <?= e($p['accent_color']) ?>; display:grid; grid-template-columns:auto 1fr auto; gap:22px; align-items:center">
        <span class="slab logo-plate" data-gen="<?= e($p['slug']) ?>" style="--pc: <?= e($p['accent_color']) ?>; min-height:auto; width:130px; aspect-ratio:1; padding:16px; justify-content:center; align-items:center">
          <?php if ($p['logo_path']): ?><img class="logo" src="<?= e(platform_logo($p)) ?>" alt="" style="width:84px;height:84px"><?php else: ?><span class="glyph"><?= e($p['short_name']) ?></span><?php endif; ?>
        </span>
        <span>
          <h2 style="margin:0"><?= e($p['name']) ?></h2>
          <span class="muted"><?= e($p['manufacturer']) ?> — <?= (int) $p['release_year'] ?> — الجيل <?= (int) $p['generation'] ?></span>
          <p style="margin:8px 0 0"><?= e($p['description']) ?></p>
        </span>
        <span class="stat"><b><?= number_format((int) $p['games_count']) ?></b><span><?= ar_noun((int) $p['games_count'], 'لعبة منشورة', 'ألعاب منشورة') ?></span></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
