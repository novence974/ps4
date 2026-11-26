<?php
/** @var array $game @var array $related @var bool $isFavorite @var ?array $trailer @var bool $preview */
$g = $game;
$pc = $g['platform_color'];
$langs = $g['languages'] ? array_map('lang_name', explode(',', $g['languages'])) : [];
$bg = $g['banner_path'] ? media_url($g['banner_path'], 'lg') : cover_url($g, 'md');
$groups = [];
foreach ($g['files'] as $f) { $groups[$f['file_type']][] = $f; }
$fileIcons = ['game' => 'disc', 'update' => 'refresh', 'dlc' => 'package', 'data' => 'database', 'save' => 'save', 'other' => 'file'];
$statusTag = ['available' => 'tag-ok', 'unavailable' => 'tag-bad', 'coming_soon' => 'tag-warn'][$g['files_status']] ?? '';
$compatTag = ['perfect' => 'tag-ok', 'playable' => 'tag-ok', 'ingame' => 'tag-warn', 'menus' => 'tag-warn', 'boots' => 'tag-warn', 'nothing' => 'tag-bad', 'unknown' => ''];
?>
<section class="page-hero gen-hero" data-gen="<?= e($g['platform_slug']) ?>">
  <div class="hero-bg" aria-hidden="true"><img src="<?= e($bg) ?>" alt=""></div>
  <div class="wrap">
    <?php if ($preview): ?><div class="notice warn preview-note"><?= icon('eye-off') ?><span>هذه اللعبة مخفية عن الزوار. أنت تشاهد معاينة لأن لديك صلاحية التعديل.</span></div><?php endif; ?>
    <div class="game-head">
      <div class="game-cover"><img src="<?= e(cover_url($g, 'md')) ?>" alt="غلاف <?= e($g['title']) ?>" width="600" height="900"></div>
      <div>
        <div class="chips">
          <a class="badge" data-gen="<?= e($g['platform_slug']) ?>" href="<?= e(url('platform/' . $g['platform_slug'])) ?>" style="--pc: <?= e($pc) ?>"><?= e($g['platform_short']) ?></a>
          <?php if ($g['release_year']): ?><span class="chip"><?= icon('calendar') ?><?= (int) $g['release_year'] ?></span><?php endif; ?>
          <?php if ($g['age_rating']): ?><span class="chip"><?= icon('shield') ?><span class="ltr"><?= e($g['age_rating']) ?></span></span><?php endif; ?>
          <?php if ($g['region']): ?><span class="chip"><?= icon('globe') ?><?= e(region_name($g['region'])) ?></span><?php endif; ?>
          <span class="tag <?= e($statusTag) ?>"><?= e(files_status_names()[$g['files_status']] ?? '') ?></span>
        </div>
        <h1 class="game-title"><?= e($g['title']) ?></h1>
        <div class="row" style="gap:16px">
          <?= rating_html($g['rating'] !== null ? (float) $g['rating'] : null) ?>
          <?php if ($g['developer']): ?><span class="muted"><?= icon('building') ?> <?= e($g['developer']) ?></span><?php endif; ?>
        </div>
        <?php if ($g['categories']): ?>
          <div class="chips" style="margin-top:12px"><?php foreach ($g['categories'] as $c): ?><a class="chip" href="<?= e(url('category/' . $c['slug'])) ?>"><?= e($c['name']) ?></a><?php endforeach; ?></div>
        <?php endif; ?>
        <div class="game-actions">
          <a class="btn btn-primary" href="#files"><?= icon('download') ?>الملفات والتنزيل</a>
          <?php if (auth_user()): ?>
            <form method="post" action="<?= e(url('favorites/' . (int) $g['id'])) ?>" data-fav-form>
              <?= csrf_field() ?>
              <button class="btn<?= $isFavorite ? ' on' : '' ?>" type="submit" aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>"><?= icon('heart') ?><span data-fav-label><?= $isFavorite ? 'في المفضلة' : 'أضف للمفضلة' ?></span> <span class="muted" data-fav-count><?= (int) $g['favorite_count'] ?></span></button>
            </form>
          <?php else: ?>
            <a class="btn" href="<?= e(url('login')) ?>"><?= icon('heart') ?>أضف للمفضلة</a>
          <?php endif; ?>
          <?php if ($trailer): ?><a class="btn" href="#media"><?= icon('play') ?>الفيديو التعريفي</a><?php endif; ?>
          <button class="btn btn-icon" type="button" data-copy="<?= e(abs_url('game/' . $g['slug'])) ?>" aria-label="نسخ رابط اللعبة" title="نسخ الرابط"><?= icon('link') ?></button>
          <?php if (can('games.edit')): ?><a class="btn btn-ghost" href="<?= e(url('admin/games/' . (int) $g['id'] . '/edit')) ?>"><?= icon('edit') ?>تعديل</a><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<div class="wrap">
  <div class="game-layout">
    <div>
      <nav class="subnav glass" aria-label="أقسام الصفحة" data-subnav>
        <a href="#overview">نظرة عامة</a>
        <?php if ($trailer || $g['images']): ?><a href="#media">الصور والفيديو</a><?php endif; ?>
        <a href="#files">الملفات</a>
        <?php if ($g['versions']): ?><a href="#versions">الإصدارات</a><?php endif; ?>
        <a href="#compat">التوافق</a>
      </nav>

      <section class="block glass" id="overview">
        <h2><?= icon('info') ?>نظرة عامة</h2>
        <div class="prose"><?= $g['description'] ? e($g['description']) : '<span class="muted">لا يوجد وصف لهذه اللعبة بعد.</span>' ?></div>
      </section>

      <?php if ($trailer || $g['images']): ?>
      <section class="block glass" id="media">
        <h2><?= icon('image') ?>الصور والفيديو</h2>
        <?php if ($trailer): ?>
          <div class="trailer" style="margin-bottom:16px">
            <?php if ($trailer['type'] === 'video'): ?>
              <video controls preload="none" playsinline poster="<?= e($g['banner_path'] ? media_url($g['banner_path'], 'sm') : cover_url($g, 'md')) ?>" data-media-video><source src="<?= e($trailer['src']) ?>"></video>
            <?php elseif ($trailer['type'] === 'link'): ?>
              <a class="facade" href="<?= e($trailer['src']) ?>" target="_blank" rel="noopener noreferrer"><span><?= icon('external') ?></span></a>
            <?php else: ?>
              <button class="facade" type="button" data-embed="<?= e($trailer['src']) ?>" aria-label="تشغيل الفيديو التعريفي">
                <?php if ($g['banner_path'] || $g['images']): ?><img src="<?= e($g['banner_path'] ? media_url($g['banner_path'], 'sm') : media_url($g['images'][0]['path'], 'lg')) ?>" alt="" loading="lazy"><?php endif; ?>
                <span><?= icon('play') ?></span>
              </button>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if ($g['images']): ?>
          <div class="shots" data-gallery>
            <?php foreach ($g['images'] as $i => $img): ?>
              <button type="button" data-full="<?= e(media_url($img['path'], 'lg')) ?>" data-index="<?= $i ?>" aria-label="عرض الصورة <?= $i + 1 ?>">
                <img src="<?= e(media_url($img['path'], 'sm')) ?>" alt="<?= e($img['caption'] ?: 'لقطة من ' . $g['title']) ?>" loading="lazy" width="480" height="270">
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <section class="block glass" id="files">
        <h2><?= icon('download') ?>الملفات والتنزيل</h2>
        <?php if (!$g['files'] && !$g['links']): ?>
          <div class="notice"><?= icon('info') ?><span><?= $g['files_status'] === 'coming_soon' ? 'الملفات قيد التجهيز وستتوفر قريباً. أضف اللعبة إلى المفضلة لتصلك التحديثات.' : 'لا توجد ملفات متاحة لهذه اللعبة حالياً.' ?></span></div>
        <?php endif; ?>
        <?php foreach (file_type_names() as $type => $typeLabel): if (empty($groups[$type])) { continue; } ?>
          <div class="file-group-title"><?= icon($fileIcons[$type]) ?><?= e($typeLabel) ?> (<?= count($groups[$type]) ?>)</div>
          <div class="files">
            <?php foreach ($groups[$type] as $f): ?>
              <div class="file-row">
                <span class="ficon"><?= icon($fileIcons[$type]) ?></span>
                <div>
                  <b><?= e($f['label'] ?: $f['original_name']) ?></b>
                  <small>
                    <span class="ltr"><?= e(human_bytes((int) $f['size_bytes'])) ?></span>
                    <?php if ($f['version_label'] || $f['version_name']): ?><span>الإصدار <span class="ltr"><?= e($f['version_label'] ?: $f['version_name']) ?></span></span><?php endif; ?>
                    <?php if ($f['region']): ?><span><?= e(region_name($f['region'])) ?></span><?php endif; ?>
                    <span><?= e(access_names()[$f['access_level']] ?? '') ?></span>
                    <span><?= (int) $f['download_count'] ?> <?= ar_noun((int) $f['download_count'], 'تنزيل', 'تنزيلات') ?></span>
                  </small>
                  <?php if ($f['sha256']): ?><div class="sha mono" title="بصمة SHA-256 للتحقق من سلامة الملف">SHA-256: <?= e($f['sha256']) ?></div><?php endif; ?>
                </div>
                <?php if ($f['deny_reason'] === null): ?>
                  <a class="btn btn-primary btn-sm" href="<?= e(url('download/' . (int) $f['id'])) ?>" data-download="<?= (int) $f['id'] ?>"
                     data-name="<?= e($f['original_name']) ?>" data-size="<?= (int) $f['size_bytes'] ?>" data-sha="<?= e((string) $f['sha256']) ?>"><?= icon('download') ?>تنزيل</a>
                <?php elseif (!auth_user()): ?>
                  <a class="btn btn-sm" href="<?= e(url('login')) ?>"><?= icon('lock') ?>سجّل الدخول</a>
                <?php else: ?>
                  <span class="tag tag-warn" title="<?= e($f['deny_reason']) ?>"><?= icon('lock') ?><?= e($f['deny_reason']) ?></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($g['links']): ?>
          <div class="file-group-title"><?= icon('link') ?>روابط رسمية ومصادر مصرح بها</div>
          <div class="files">
            <?php foreach ($g['links'] as $l): ?>
              <div class="file-row">
                <span class="ficon"><?= icon('external') ?></span>
                <div><b><?= e($l['title']) ?></b><small><span><?= e(link_type_names()[$l['link_type']] ?? '') ?></span><?php if ($l['region']): ?><span><?= e(region_name($l['region'])) ?></span><?php endif; ?><span class="ltr"><?= e(parse_url($l['url'], PHP_URL_HOST) ?: '') ?></span></small></div>
                <a class="btn btn-sm" href="<?= e($l['url']) ?>" target="_blank" rel="noopener noreferrer nofollow" data-link-id="<?= (int) $l['id'] ?>"><?= icon('external') ?>فتح</a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($g['files']): ?>
          <p class="muted" style="font-size:var(--fs-xs); margin:14px 0 0">يعمل التنزيل عبر مدير التنزيل مع إمكانية الإيقاف والاستكمال وإظهار السرعة والمتبقي، ويتحقق من بصمة SHA-256 بعد الانتهاء.</p>
        <?php endif; ?>
      </section>

      <?php if ($g['versions']): ?>
      <section class="block glass" id="versions">
        <h2><?= icon('history') ?>الإصدارات والتحديثات</h2>
        <ul class="timeline">
          <?php foreach ($g['versions'] as $v): ?>
            <li class="<?= $v['is_latest'] ? 'latest' : '' ?>">
              <div class="row" style="gap:8px"><b class="ltr">v<?= e($v['version']) ?></b><?php if ($v['is_latest']): ?><span class="tag tag-ok">الأحدث</span><?php endif; ?><?php if ($v['region']): ?><span class="tag"><?= e(region_name($v['region'])) ?></span><?php endif; ?>
                <span class="faint" style="font-size:var(--fs-xs)"><?= e($v['release_date'] ? fmt_date($v['release_date'] . ' 00:00:00') : fmt_date($v['created_at'])) ?><?= $v['size_bytes'] ? ' — ' . e(human_bytes((int) $v['size_bytes'])) : '' ?></span></div>
              <?php if ($v['changelog']): ?><div class="prose muted" style="font-size:var(--fs-sm); margin-top:4px"><?= e($v['changelog']) ?></div><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <section class="block glass" id="compat">
        <h2><?= icon('compare') ?>معلومات التوافق</h2>
        <?php if ($g['compat']): ?>
          <div class="table-wrap" style="margin-bottom:14px">
            <table class="table">
              <thead><tr><th>الجهاز أو المحاكي</th><th>الحالة</th><th>ملاحظات</th><th>آخر اختبار</th></tr></thead>
              <tbody>
              <?php foreach ($g['compat'] as $c): ?>
                <tr><td><?= e($c['target']) ?></td><td><span class="tag <?= e($compatTag[$c['status']] ?? '') ?>"><?= e(compat_names()[$c['status']] ?? $c['status']) ?></span></td><td class="muted"><?= e($c['notes'] ?? '') ?></td><td class="muted"><?= e($c['tested_at'] ?? '—') ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <?php if ($g['compatibility_notes']): ?><div class="prose" style="margin-bottom:14px"><?= e($g['compatibility_notes']) ?></div><?php endif; ?>
        <div class="grid" style="gap:10px">
          <?php if ($g['platform_formats']): ?><div class="notice"><?= icon('disc') ?><span><b>الصيغ المدعومة على <?= e($g['platform_short']) ?>:</b> <?= e($g['platform_formats']) ?></span></div><?php endif; ?>
          <?php if ($g['platform_compat']): ?><div class="notice"><?= icon('compare') ?><span><?= e($g['platform_compat']) ?></span></div><?php endif; ?>
          <?php if ($g['platform_install']): ?><div class="notice ok"><?= icon('shield') ?><span><?= e($g['platform_install']) ?></span></div><?php endif; ?>
        </div>
      </section>
    </div>

    <aside class="sticky-side">
      <div class="glass panel">
        <h2 style="font-size:1.1rem">بطاقة اللعبة</h2>
        <dl class="facts">
          <dt><?= icon('gamepad') ?>الجهاز</dt><dd><a href="<?= e(url('platform/' . $g['platform_slug'])) ?>"><?= e($g['platform_name']) ?></a></dd>
          <dt><?= icon('calendar') ?>سنة الإصدار</dt><dd><?= e((string) ($g['release_year'] ?? '—')) ?></dd>
          <dt><?= icon('building') ?>المطور</dt><dd><?= e($g['developer'] ?? '—') ?></dd>
          <dt><?= icon('building') ?>الناشر</dt><dd><?= e($g['publisher'] ?? '—') ?></dd>
          <dt><?= icon('shield') ?>التصنيف العمري</dt><dd class="ltr"><?= e($g['age_rating'] ?? '—') ?></dd>
          <dt><?= icon('tag') ?>النوع</dt><dd><?= e(implode('، ', array_column($g['categories'], 'name')) ?: '—') ?></dd>
          <dt><?= icon('drive') ?>الحجم</dt><dd class="ltr"><?= e(human_bytes($g['size_bytes'] !== null ? (int) $g['size_bytes'] : null)) ?></dd>
          <dt><?= icon('languages') ?>اللغة</dt><dd><?= e($langs ? implode('، ', $langs) : '—') ?></dd>
          <dt><?= icon('globe') ?>المنطقة</dt><dd><?= e(region_name($g['region'])) ?></dd>
          <dt><?= icon('layers') ?>الإصدار</dt><dd class="ltr"><?= e(trim(($g['version'] ? 'v' . $g['version'] : '') . ($g['edition'] ? ' ' . $g['edition'] : '')) ?: '—') ?></dd>
          <?php if ($g['product_code']): ?><dt><?= icon('hash') ?>رمز المنتج</dt><dd class="mono"><?= e($g['product_code']) ?></dd><?php endif; ?>
          <dt><?= icon('star') ?>التقييم</dt><dd><?= $g['rating'] !== null ? e(number_format((float) $g['rating'], 1)) . ' / 10' : '—' ?></dd>
          <dt><?= icon('package') ?>حالة الملفات</dt><dd><span class="tag <?= e($statusTag) ?>"><?= e(files_status_names()[$g['files_status']] ?? '') ?></span></dd>
          <dt><?= icon('clock') ?>آخر تحديث</dt><dd><?= e(fmt_date($g['updated_at'])) ?></dd>
          <dt><?= icon('eye') ?>المشاهدات</dt><dd><?= number_format((int) $g['view_count']) ?></dd>
          <dt><?= icon('download') ?>التنزيلات</dt><dd><?= number_format((int) $g['download_count']) ?></dd>
        </dl>
      </div>
    </aside>
  </div>

  <?= partial('rail', ['id' => 'h-related', 'heading' => 'ألعاب مشابهة', 'more' => '/games?platform=' . $g['platform_slug'] . (isset($g['categories'][0]) ? '&category=' . $g['categories'][0]['slug'] : ''), 'games' => $related, 'favIds' => $favIds]) ?>
</div>
