<div class="toolbar"><span class="spacer"></span><a class="btn btn-sm btn-primary" href="<?= e(url('/admin/platforms/create')) ?>"><?= icon('plus') ?>إضافة جهاز</a></div>
<div class="tiles" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr))">
  <?php foreach ($platforms as $p): ?>
    <a class="slab" href="<?= e(url('/admin/platforms/' . (int) $p['id'] . '/edit')) ?>" style="--pc: <?= e($p['accent_color']) ?>; min-height:170px">
      <span class="row" style="justify-content:space-between; position:relative"><?php if ($p['logo_path']): ?><img class="logo" src="<?= e(platform_logo($p)) ?>" alt=""><?php else: ?><span class="glyph"><?= e($p['short_name']) ?></span><?php endif; ?><?= $p['is_active'] ? '<span class="tag tag-ok">فعال</span>' : '<span class="tag">معطل</span>' ?></span>
      <span style="position:relative"><b class="name"><?= e($p['name']) ?></b><br><span class="count"><?= (int) $p['games_count'] ?> <?= ar_noun((int) $p['games_count'], 'لعبة منشورة', 'ألعاب منشورة') ?> — ترتيب <?= (int) $p['sort_order'] ?></span></span>
    </a>
  <?php endforeach; ?>
</div>
