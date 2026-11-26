<?php if (!empty($games)): ?>
<section class="section" aria-labelledby="<?= e($id) ?>">
  <div class="section-head">
    <h2 id="<?= e($id) ?>"><?= e($heading) ?></h2>
    <div class="row">
      <a class="more" href="<?= e(url($more)) ?>">عرض الكل <?= icon('chev-left') ?></a>
      <div class="rail-nav hide-sm">
        <button class="icon-btn" type="button" data-rail="prev" aria-label="السابق"><?= icon('chev-right') ?></button>
        <button class="icon-btn" type="button" data-rail="next" aria-label="التالي"><?= icon('chev-left') ?></button>
      </div>
    </div>
  </div>
  <div class="rail" data-rail-track>
    <?= partial('game-grid', ['games' => $games, 'favIds' => $favIds ?? []]) ?>
  </div>
</section>
<?php endif; ?>
