<?php
/** @var ?array $game @var array $platforms @var array $categories @var string $tab */
$g = $game;
$isEdit = $g !== null;
$v = static fn (string $k, $d = '') => old($k, $g[$k] ?? $d);
$gid = $isEdit ? (int) $g['id'] : 0;
$langs = $isEdit && $g['languages'] ? explode(',', $g['languages']) : [];
$oldLangs = old('languages', null);
if (is_array($oldLangs)) { $langs = $oldLangs; }
$cats = $isEdit ? array_column($g['categories'], 'id') : [];
$oldCats = old('categories', null);
if (is_array($oldCats)) { $cats = array_map('intval', $oldCats); }
$tabs = ['details' => ['edit', 'البيانات']];
if ($isEdit) {
    $tabs += ['media' => ['image', 'الغلاف والصور والفيديو'], 'files' => ['files', 'الملفات'], 'versions' => ['history', 'الإصدارات'], 'links' => ['link', 'الروابط'], 'compat' => ['compare', 'التوافق']];
}
$tab = isset($tabs[$tab]) ? $tab : 'details';
$counts = $isEdit ? ['files' => count($g['files']), 'versions' => count($g['versions']), 'links' => count($g['links']), 'compat' => count($g['compat']), 'media' => count($g['images'])] : [];
$dupErr = field_error('title');
$base = $isEdit ? url('/admin/games/' . $gid) : '';
?>
<?php if ($isEdit): ?>
<div class="toolbar">
  <div class="g-row"><img src="<?= e(cover_url($g, 'sm')) ?>" alt=""><div><b><?= e($g['title']) ?></b><small><span class="badge" style="--pc: <?= e($g['platform_color']) ?>"><?= e($g['platform_short']) ?></span> <?= $g['is_published'] ? '<span class="tag tag-ok">منشورة</span>' : '<span class="tag tag-warn">مخفية</span>' ?> — آخر تعديل <?= e(time_ago($g['updated_at'])) ?></small></div></div>
  <span class="spacer"></span>
  <a class="btn btn-sm btn-ghost" href="<?= e(url('game/' . $g['slug'])) ?>" target="_blank" rel="noopener"><?= icon('external') ?>عرض في الموقع</a>
  <?php if (can('games.publish')): ?>
    <form method="post" action="<?= e($base . '/publish') ?>"><?= csrf_field() ?><input type="hidden" name="published" value="<?= $g['is_published'] ? '0' : '1' ?>"><button class="btn btn-sm" type="submit"><?= icon($g['is_published'] ? 'eye-off' : 'eye') ?><?= $g['is_published'] ? 'إخفاء' : 'نشر' ?></button></form>
  <?php endif; ?>
  <?php if (can('games.delete')): ?>
    <form method="post" action="<?= e($base . '/delete') ?>" data-confirm="حذف «<?= e($g['title']) ?>» مع كل ملفاتها وصورها نهائياً؟"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= icon('trash') ?>حذف</button></form>
  <?php endif; ?>
</div>
<?php endif; ?>

<nav class="tabs glass" data-tabs aria-label="أقسام اللعبة">
  <?php foreach ($tabs as $k => [$ic, $label]): ?>
    <a href="?tab=<?= $k ?>" data-tab="<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= icon($ic) ?><?= e($label) ?><?php if (!empty($counts[$k])): ?><span class="count"><?= (int) $counts[$k] ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>

<!-- ================================================================ details -->
<section class="tab-panel" data-panel="details" <?= $tab !== 'details' ? 'hidden' : '' ?>>
  <form method="post" action="<?= e($isEdit ? $base : url('/admin/games')) ?>" class="glass card" data-game-form data-exclude="<?= $gid ?>">
    <?= csrf_field() ?>
    <div class="form-grid">
      <label class="field<?= field_error('platform_id') ? ' has-error' : '' ?>"><span>الجهاز *</span>
        <select name="platform_id" required data-dup-src>
          <option value="">اختر الجهاز</option>
          <?php foreach ($platforms as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $v('platform_id') === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select><?php if ($er = field_error('platform_id')): ?><small class="error"><?= e($er) ?></small><?php endif; ?></label>
      <label class="field<?= $dupErr ? ' has-error' : '' ?>"><span>اسم اللعبة *</span>
        <input type="text" name="title" value="<?= e($v('title')) ?>" required maxlength="190" data-dup-src autocomplete="off">
        <?php if ($dupErr): ?><small class="error"><?= e($dupErr) ?></small><?php endif; ?></label>
      <div class="full dup-box" data-dup-box></div>
      <?php if ($dupErr): ?><label class="check full"><input type="checkbox" name="allow_duplicate" value="1"><span>أعلم أنها مطابقة للعبة موجودة، وهي إصدار أو منطقة مختلفة — احفظها على أي حال</span></label><?php endif; ?>
      <label class="field"><span>رمز المنتج (Title ID / Serial)</span><input type="text" name="product_code" value="<?= e($v('product_code')) ?>" maxlength="32" dir="ltr" data-dup-src placeholder="مثال: DEMO-50001"><?php if ($er = field_error('product_code')): ?><small class="error"><?= e($er) ?></small><?php endif; ?></label>
      <label class="field"><span>المنطقة</span>
        <select name="region" data-dup-src><option value="">غير محددة</option><?php foreach (region_names() as $k => $l): ?><option value="<?= $k ?>" <?= $v('region') === $k ? 'selected' : '' ?>><?= e($l) ?> (<?= $k ?>)</option><?php endforeach; ?></select></label>
      <label class="field<?= field_error('release_year') ? ' has-error' : '' ?>"><span>سنة الإصدار</span><input type="number" name="release_year" value="<?= e($v('release_year')) ?>" min="1970" max="2100"><?php if ($er = field_error('release_year')): ?><small class="error"><?= e($er) ?></small><?php endif; ?></label>
      <label class="field"><span>التصنيف العمري</span><input type="text" name="age_rating" value="<?= e($v('age_rating')) ?>" list="ages" maxlength="24" dir="ltr">
        <datalist id="ages"><?php foreach (['PEGI 3', 'PEGI 7', 'PEGI 12', 'PEGI 16', 'PEGI 18', 'ESRB E', 'ESRB E10+', 'ESRB T', 'ESRB M', 'CERO A', 'CERO B', 'CERO C', 'CERO D', 'CERO Z'] as $a): ?><option value="<?= $a ?>"></option><?php endforeach; ?></datalist></label>
      <label class="field"><span>الشركة المطورة</span><input type="text" name="developer" value="<?= e($v('developer')) ?>" maxlength="128"></label>
      <label class="field"><span>الشركة الناشرة</span><input type="text" name="publisher" value="<?= e($v('publisher')) ?>" maxlength="128"></label>
      <label class="field<?= field_error('size') ? ' has-error' : '' ?>"><span>حجم اللعبة</span><input type="text" name="size" value="<?= e(old('size', $isEdit && $g['size_bytes'] ? human_bytes((int) $g['size_bytes'], 2) : '')) ?>" maxlength="40" dir="ltr" placeholder="45.2 GB"><small class="hint">يُحسب تلقائياً من الملفات إن تُرك فارغاً</small><?php if ($er = field_error('size')): ?><small class="error"><?= e($er) ?></small><?php endif; ?></label>
      <label class="field<?= field_error('rating') ? ' has-error' : '' ?>"><span>التقييم (من 10)</span><input type="number" name="rating" value="<?= e($v('rating')) ?>" min="0" max="10" step="0.1"><?php if ($er = field_error('rating')): ?><small class="error"><?= e($er) ?></small><?php endif; ?></label>
      <label class="field"><span>الإصدار الحالي</span><input type="text" name="version" value="<?= e($v('version')) ?>" maxlength="32" dir="ltr" placeholder="1.00"></label>
      <label class="field"><span>النسخة</span><input type="text" name="edition" value="<?= e($v('edition')) ?>" maxlength="64" placeholder="Standard / Deluxe / GOTY"></label>
      <label class="field full<?= field_error('trailer_url') ? ' has-error' : '' ?>"><span>رابط الفيديو التعريفي</span><input type="text" name="trailer_url" value="<?= e($v('trailer_url')) ?>" maxlength="500" dir="ltr" placeholder="https://www.youtube.com/watch?v=… أو رابط MP4"><small class="hint">يدعم YouTube و Vimeo وملفات MP4، ويمكن رفع ملف فيديو من تبويب الوسائط.</small><?php if ($er = field_error('trailer_url')): ?><small class="error"><?= e($er) ?></small><?php endif; ?></label>
      <div class="field full"><span class="label">التصنيفات</span>
        <div class="checks"><?php foreach ($categories as $c): ?><label><input type="checkbox" name="categories[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $cats, true) ? 'checked' : '' ?>><?= e($c['name']) ?></label><?php endforeach; ?></div></div>
      <div class="field full"><span class="label">اللغات</span>
        <div class="checks"><?php foreach (lang_names() as $code => $name): ?><label><input type="checkbox" name="languages[]" value="<?= e($code) ?>" <?= in_array($code, $langs, true) ? 'checked' : '' ?>><?= e($name) ?></label><?php endforeach; ?></div></div>
      <label class="field full"><span>الوصف</span><textarea name="description" rows="6" maxlength="20000"><?= e($v('description')) ?></textarea></label>
      <label class="field full"><span>ملاحظات التوافق</span><textarea name="compatibility_notes" rows="3" maxlength="5000" placeholder="مثال: تعمل على PS5 عبر التوافق الرجعي مع تحسين معدل الإطارات"><?= e($v('compatibility_notes')) ?></textarea></label>
      <label class="field"><span>حالة توفر الملفات</span>
        <select name="files_status"><option value="">تلقائي حسب الملفات المرفوعة</option><option value="coming_soon" <?= $v('files_status') === 'coming_soon' ? 'selected' : '' ?>>قريباً</option></select></label>
      <div class="field">
        <?php if (can('games.publish')): ?>
          <input type="hidden" name="is_published" value="0"><label class="switch"><span>منشورة للزوار<small>تظهر في المكتبة والبحث والتطبيقات</small></span><input type="checkbox" name="is_published" value="1" <?= $v('is_published') ? 'checked' : '' ?>></label>
          <input type="hidden" name="is_featured" value="0"><label class="switch"><span>مميزة في الواجهة<small>تظهر في الشريط الرئيسي</small></span><input type="checkbox" name="is_featured" value="1" <?= $v('is_featured') ? 'checked' : '' ?>></label>
        <?php else: ?><p class="muted">النشر يتطلب صلاحية «نشر وإخفاء الألعاب».</p><?php endif; ?>
      </div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('save') ?><?= $isEdit ? 'حفظ التعديلات' : 'إضافة اللعبة' ?></button><a class="btn btn-ghost" href="<?= e(url('/admin/games')) ?>">رجوع للقائمة</a></div>
  </form>
</section>

<?php if ($isEdit): ?>
<!-- ================================================================ media -->
<section class="tab-panel" data-panel="media" <?= $tab !== 'media' ? 'hidden' : '' ?>>
  <div class="grid-2">
    <div class="glass card">
      <h2><?= icon('image') ?>غلاف اللعبة</h2>
      <div class="media-grid" style="grid-template-columns:150px 1fr">
        <div class="media-preview"><img src="<?= e(cover_url($g, 'md')) ?>" alt=""></div>
        <form method="post" action="<?= e($base . '/image/cover') ?>" enctype="multipart/form-data"><?= csrf_field() ?>
          <input type="file" name="file" accept="image/jpeg,image/png,image/webp,image/gif" required>
          <p class="muted" style="font-size:var(--fs-xs)">يفضل 600×900 أو أكبر بنسبة 2:3. تُحوّل تلقائياً إلى WebP وJPEG بمقاسين.</p>
          <button class="btn btn-sm btn-primary" type="submit"><?= icon('upload') ?>رفع الغلاف</button></form>
        <form method="post" action="<?= e($base . '/image/cover') ?>" class="row" style="margin-top:10px"><?= csrf_field() ?>
          <input type="url" name="url" placeholder="أو رابط صورة من الإنترنت https://…" pattern="https://.*" maxlength="255" required style="flex:1" dir="ltr">
          <button class="btn btn-sm" type="submit"><?= icon('link') ?>حفظ الرابط</button></form>
      </div>
    </div>
    <div class="glass card">
      <h2><?= icon('image') ?>صورة الخلفية (Banner)</h2>
      <div class="media-preview wide" style="margin-bottom:12px"><?php if ($g['banner_path']): ?><img src="<?= e(media_url($g['banner_path'], 'sm')) ?>" alt=""><?php endif; ?></div>
      <form method="post" action="<?= e($base . '/image/banner') ?>" enctype="multipart/form-data"><?= csrf_field() ?>
        <input type="file" name="file" accept="image/jpeg,image/png,image/webp,image/gif" required>
        <div class="form-actions" style="margin-top:10px"><button class="btn btn-sm btn-primary" type="submit"><?= icon('upload') ?>رفع الخلفية</button></div></form>
      <form method="post" action="<?= e($base . '/image/banner') ?>" class="row" style="margin-top:10px"><?= csrf_field() ?>
          <input type="url" name="url" placeholder="أو رابط صورة من الإنترنت https://…" pattern="https://.*" maxlength="255" required style="flex:1" dir="ltr">
          <button class="btn btn-sm" type="submit"><?= icon('link') ?>حفظ الرابط</button></form>
    </div>
  </div>
  <div class="glass card">
    <h2><?= icon('image') ?>صور من داخل اللعبة <span class="muted" style="font-size:var(--fs-sm)">(اسحب لإعادة الترتيب)</span></h2>
    <form method="post" action="<?= e($base . '/screenshots') ?>" enctype="multipart/form-data" class="row" style="margin-bottom:14px"><?= csrf_field() ?>
      <input type="file" name="files[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required style="flex:1">
      <button class="btn btn-sm btn-primary" type="submit"><?= icon('upload') ?>رفع الصور</button></form>
    <form method="post" action="<?= e($base . '/screenshots') ?>" class="row" style="margin-bottom:14px"><?= csrf_field() ?>
      <input type="url" name="url" placeholder="أو رابط صورة من الإنترنت https://…" pattern="https://.*" maxlength="255" required style="flex:1" dir="ltr">
      <button class="btn btn-sm" type="submit"><?= icon('link') ?>إضافة بالرابط</button></form>
    <?php if ($g['images']): ?>
      <div class="thumbs" data-sortable="<?= e($base . '/screenshots/reorder') ?>">
        <?php foreach ($g['images'] as $img): ?>
          <div class="thumb-item" draggable="true" data-id="<?= (int) $img['id'] ?>">
            <img src="<?= e(media_url($img['path'], 'sm')) ?>" alt="" loading="lazy">
            <form method="post" action="<?= e($base . '/screenshots/' . (int) $img['id'] . '/delete') ?>" data-confirm="حذف هذه الصورة؟"><?= csrf_field() ?><button class="btn btn-xs btn-danger btn-icon" type="submit" aria-label="حذف الصورة"><?= icon('trash') ?></button></form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?><p class="muted">لا توجد صور بعد.</p><?php endif; ?>
  </div>
  <div class="glass card">
    <h2><?= icon('film') ?>الفيديو التعريفي</h2>
    <?php if ($g['trailer_url']): ?>
      <div class="notice" style="margin-bottom:12px"><?= icon('film') ?><span style="flex:1">الحالي: <span class="code-inline"><?= e($g['trailer_url']) ?></span></span>
        <form method="post" action="<?= e($base . '/trailer/remove') ?>" data-confirm="إزالة الفيديو؟"><?= csrf_field() ?><button class="btn btn-xs btn-danger" type="submit">إزالة</button></form></div>
    <?php endif; ?>
    <p class="muted" style="font-size:var(--fs-sm)">ارفع ملف MP4/WebM (حتى 4GB، رفع مجزأ قابل للاستكمال) أو ضع رابط YouTube في تبويب البيانات.</p>
    <div class="uploader" data-uploader data-purpose="trailer" data-game-id="<?= $gid ?>" data-accept=".mp4,.webm" data-reload="1">
      <label class="drop"><?= icon('film', 'big') ?><b>اسحب ملف الفيديو هنا أو اضغط للاختيار</b><small>MP4 أو WebM</small><input type="file" accept=".mp4,.webm,video/mp4,video/webm" data-input></label>
      <div data-queue></div>
    </div>
  </div>
</section>

<!-- ================================================================ files -->
<section class="tab-panel" data-panel="files" <?= $tab !== 'files' ? 'hidden' : '' ?>>
  <?php if (can('files.upload')): ?>
  <div class="glass card">
    <h2><?= icon('cloud-upload') ?>رفع ملفات اللعبة</h2>
    <div class="uploader" data-uploader data-purpose="game_file" data-game-id="<?= $gid ?>" data-accept="<?= e('.' . implode(',.', $allowedExt)) ?>" data-multiple="1" data-reload="1">
      <div class="inline-form">
        <label class="field"><span>نوع الملف</span><select data-meta="file_type"><?php foreach (file_type_names() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>صلاحية التنزيل</span><select data-meta="access_level"><?php foreach (access_names() as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'registered' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>المنطقة</span><select data-meta="region"><option value="">—</option><?php foreach (region_names() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>الإصدار</span><select data-meta="version_id"><option value="">—</option><?php foreach ($g['versions'] as $ver): ?><option value="<?= (int) $ver['id'] ?>">v<?= e($ver['version']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>وصف مختصر (اختياري)</span><input type="text" data-meta="label" maxlength="190" placeholder="مثال: النسخة الكاملة"></label>
      </div>
      <label class="drop"><?= icon('cloud-upload', 'big') ?><b>اسحب الملفات هنا أو اضغط للاختيار</b>
        <small>رفع على أجزاء مع استكمال تلقائي بعد انقطاع الاتصال وتحقق SHA-256. الامتدادات: <?= e(implode('، ', array_slice($allowedExt, 0, 14))) ?>…</small>
        <input type="file" multiple data-input></label>
      <div data-queue></div>
    </div>
    <?php if (!empty($uploads)): ?>
      <h3 style="margin-top:18px; font-size:1rem">عمليات رفع غير مكتملة لهذه اللعبة</h3>
      <p class="muted" style="font-size:var(--fs-xs)">لاستكمال رفع متوقف اختر الملف نفسه مرة أخرى وسيكمل من حيث توقف.</p>
      <ul class="list"><?php foreach ($uploads as $up): ?><li><span class="tag <?= $up['status'] === 'failed' ? 'tag-bad' : 'tag-warn' ?>"><?= e($up['status']) ?></span><div class="grow"><b class="ltr"><?= e($up['original_name']) ?></b><small><?= e(human_bytes((int) $up['received_bytes'])) ?> / <?= e(human_bytes((int) $up['size_bytes'])) ?><?= $up['error'] ? ' — ' . e($up['error']) : '' ?></small></div></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="glass card">
    <h2><?= icon('files') ?>الملفات المستضافة (<?= count($g['files']) ?>)</h2>
    <?php if (!$g['files']): ?><p class="muted">لم تُرفع ملفات بعد.</p><?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>الملف</th><th>النوع</th><th>الحجم</th><th>الصلاحية</th><th>التنزيلات</th><th>الحالة</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($g['files'] as $f): ?>
        <tr>
          <td><b class="ltr" style="overflow-wrap:anywhere"><?= e($f['original_name']) ?></b><?php if ($f['label']): ?><br><small class="muted"><?= e($f['label']) ?></small><?php endif; ?><br><small class="mono faint" title="SHA-256"><?= e(substr((string) $f['sha256'], 0, 24)) ?>…</small></td>
          <td><?= e(file_type_names()[$f['file_type']] ?? '') ?></td>
          <td class="ltr nowrap"><?= e(human_bytes((int) $f['size_bytes'])) ?></td>
          <td><?= e(access_names()[$f['access_level']] ?? '') ?></td>
          <td><?= (int) $f['download_count'] ?></td>
          <td><?= $f['is_active'] ? '<span class="tag tag-ok">فعال</span>' : '<span class="tag">معطل</span>' ?><?= $f['verified_at'] ? '' : ' <span class="tag tag-warn">غير متحقق</span>' ?></td>
          <td><div class="actions">
            <?php if (can('files.manage')): ?>
              <form method="post" action="<?= e(url('/admin/files/' . (int) $f['id'] . '/verify')) ?>"><?= csrf_field() ?><button class="btn btn-xs" type="submit" title="إعادة حساب SHA-256"><?= icon('shield') ?>تحقق</button></form>
              <form method="post" action="<?= e(url('/admin/files/' . (int) $f['id'] . '/delete')) ?>" data-confirm="حذف الملف من الخادم نهائياً؟"><?= csrf_field() ?><button class="btn btn-xs btn-danger btn-icon" type="submit" aria-label="حذف"><?= icon('trash') ?></button></form>
            <?php endif; ?>
          </div></td>
        </tr>
        <?php if (can('files.manage')): ?>
        <tr><td colspan="7" style="padding-top:0">
          <details class="edit-row"><summary class="muted" style="font-size:var(--fs-xs)"><?= icon('edit') ?> تعديل بيانات الملف</summary>
            <form method="post" action="<?= e(url('/admin/files/' . (int) $f['id'])) ?>" class="inline-form"><?= csrf_field() ?>
              <label class="field"><span>الوصف</span><input type="text" name="label" value="<?= e($f['label']) ?>" maxlength="190"></label>
              <label class="field"><span>النوع</span><select name="file_type"><?php foreach (file_type_names() as $k => $l): ?><option value="<?= $k ?>" <?= $f['file_type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
              <label class="field"><span>الصلاحية</span><select name="access_level"><?php foreach (access_names() as $k => $l): ?><option value="<?= $k ?>" <?= $f['access_level'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
              <label class="field"><span>المنطقة</span><select name="region"><option value="">—</option><?php foreach (region_names() as $k => $l): ?><option value="<?= $k ?>" <?= $f['region'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
              <label class="field"><span>رقم الإصدار</span><input type="text" name="version_label" value="<?= e($f['version_label']) ?>" maxlength="32" dir="ltr"></label>
              <input type="hidden" name="is_active" value="0"><label class="check"><input type="checkbox" name="is_active" value="1" <?= $f['is_active'] ? 'checked' : '' ?>>فعال</label>
              <button class="btn btn-sm btn-primary" type="submit"><?= icon('save') ?>حفظ</button>
            </form></details></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </div>
</section>

<!-- ================================================================ versions -->
<section class="tab-panel" data-panel="versions" <?= $tab !== 'versions' ? 'hidden' : '' ?>>
  <div class="glass card">
    <h2><?= icon('plus') ?>إضافة إصدار / تحديث</h2>
    <form method="post" action="<?= e($base . '/versions') ?>"><?= csrf_field() ?>
      <div class="inline-form">
        <label class="field"><span>رقم الإصدار *</span><input type="text" name="version" required maxlength="32" dir="ltr" placeholder="1.05"></label>
        <label class="field"><span>المنطقة</span><select name="region"><option value="">—</option><?php foreach (region_names() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>تاريخ الإصدار</span><input type="date" name="release_date"></label>
        <label class="field"><span>الحجم</span><input type="text" name="size" maxlength="40" dir="ltr" placeholder="1.2 GB"></label>
      </div>
      <label class="field" style="margin-top:10px"><span>سجل التغييرات</span><textarea name="changelog" rows="3" maxlength="10000"></textarea></label>
      <div class="row" style="margin-top:8px">
        <input type="hidden" name="is_latest" value="0"><label class="check"><input type="checkbox" name="is_latest" value="1" checked>تعيينه كأحدث إصدار</label>
        <input type="hidden" name="notify" value="0"><label class="check"><input type="checkbox" name="notify" value="1" checked>نشر تحديث وإشعار من أضافها للمفضلة</label>
        <span class="spacer"></span><button class="btn btn-primary btn-sm" type="submit"><?= icon('save') ?>إضافة الإصدار</button>
      </div>
    </form>
  </div>
  <div class="glass card">
    <h2><?= icon('history') ?>سجل الإصدارات</h2>
    <?php if (!$g['versions']): ?><p class="muted">لا توجد إصدارات مسجلة.</p><?php endif; ?>
    <ul class="list">
      <?php foreach ($g['versions'] as $ver): ?>
        <li style="display:block">
          <div class="row"><b class="ltr">v<?= e($ver['version']) ?></b><?= $ver['is_latest'] ? '<span class="tag tag-ok">الأحدث</span>' : '' ?><span class="muted"><?= e($ver['release_date'] ?? '') ?> <?= e(region_name($ver['region'])) ?></span><span class="spacer"></span>
            <form method="post" action="<?= e($base . '/versions/' . (int) $ver['id'] . '/delete') ?>" data-confirm="حذف الإصدار؟"><?= csrf_field() ?><button class="btn btn-xs btn-danger btn-icon" type="submit" aria-label="حذف"><?= icon('trash') ?></button></form></div>
          <?php if ($ver['changelog']): ?><p class="muted prose" style="font-size:var(--fs-sm); margin:6px 0 0"><?= e($ver['changelog']) ?></p><?php endif; ?>
          <details class="edit-row"><summary class="muted" style="font-size:var(--fs-xs)"><?= icon('edit') ?> تعديل</summary>
            <form method="post" action="<?= e($base . '/versions/' . (int) $ver['id']) ?>"><?= csrf_field() ?>
              <div class="inline-form">
                <label class="field"><span>رقم الإصدار</span><input type="text" name="version" value="<?= e($ver['version']) ?>" required dir="ltr"></label>
                <label class="field"><span>المنطقة</span><select name="region"><option value="">—</option><?php foreach (region_names() as $k => $l): ?><option value="<?= $k ?>" <?= $ver['region'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>التاريخ</span><input type="date" name="release_date" value="<?= e($ver['release_date']) ?>"></label>
                <label class="field"><span>الحجم</span><input type="text" name="size" value="<?= e($ver['size_bytes'] ? human_bytes((int) $ver['size_bytes'], 2) : '') ?>" dir="ltr"></label>
              </div>
              <label class="field"><span>سجل التغييرات</span><textarea name="changelog" rows="3"><?= e($ver['changelog']) ?></textarea></label>
              <div class="row"><input type="hidden" name="is_latest" value="0"><label class="check"><input type="checkbox" name="is_latest" value="1" <?= $ver['is_latest'] ? 'checked' : '' ?>>الأحدث</label><input type="hidden" name="notify" value="0"><span class="spacer"></span><button class="btn btn-sm btn-primary" type="submit"><?= icon('save') ?>حفظ</button></div>
            </form></details>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<!-- ================================================================ links -->
<section class="tab-panel" data-panel="links" <?= $tab !== 'links' ? 'hidden' : '' ?>>
  <div class="glass card">
    <h2><?= icon('link') ?>إضافة رابط</h2>
    <form method="post" action="<?= e($base . '/links') ?>" class="inline-form"><?= csrf_field() ?>
      <label class="field"><span>العنوان *</span><input type="text" name="title" required maxlength="190" placeholder="مثال: PKG v1.02"></label>
      <label class="field" style="grid-column: span 2"><span>الرابط *</span><input type="url" name="url" required maxlength="1000" dir="ltr" placeholder="https://"></label>
      <label class="field"><span>النوع</span><select name="link_type"><?php foreach (link_type_names() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>المنطقة</span><select name="region"><option value="">—</option><?php foreach (region_names() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
      <input type="hidden" name="is_active" value="1">
      <button class="btn btn-primary" type="submit"><?= icon('plus') ?>إضافة</button>
    </form>
  </div>
  <div class="glass card">
    <h2><?= icon('list') ?>الروابط (<?= count($g['links']) ?>)</h2>
    <?php if (!$g['links']): ?><p class="muted">لا توجد روابط.</p><?php endif; ?>
    <ul class="list">
      <?php foreach ($g['links'] as $l): ?>
        <li style="display:block">
          <div class="row"><b><?= e($l['title']) ?></b><span class="tag"><?= e(link_type_names()[$l['link_type']] ?? '') ?></span><?= $l['is_active'] ? '' : '<span class="tag">معطل</span>' ?><a class="code-inline" href="<?= e($l['url']) ?>" target="_blank" rel="noopener noreferrer" style="overflow-wrap:anywhere"><?= e($l['url']) ?></a><span class="spacer"></span>
            <form method="post" action="<?= e($base . '/links/' . (int) $l['id'] . '/delete') ?>" data-confirm="حذف الرابط؟"><?= csrf_field() ?><button class="btn btn-xs btn-danger btn-icon" type="submit" aria-label="حذف"><?= icon('trash') ?></button></form></div>
          <details class="edit-row"><summary class="muted" style="font-size:var(--fs-xs)"><?= icon('edit') ?> تعديل</summary>
            <form method="post" action="<?= e($base . '/links/' . (int) $l['id']) ?>" class="inline-form"><?= csrf_field() ?>
              <label class="field"><span>العنوان</span><input type="text" name="title" value="<?= e($l['title']) ?>" required></label>
              <label class="field"><span>الرابط</span><input type="url" name="url" value="<?= e($l['url']) ?>" required dir="ltr"></label>
              <label class="field"><span>النوع</span><select name="link_type"><?php foreach (link_type_names() as $k => $t): ?><option value="<?= $k ?>" <?= $l['link_type'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
              <label class="field"><span>الترتيب</span><input type="number" name="sort_order" value="<?= (int) $l['sort_order'] ?>"></label>
              <input type="hidden" name="is_active" value="0"><label class="check"><input type="checkbox" name="is_active" value="1" <?= $l['is_active'] ? 'checked' : '' ?>>فعال</label>
              <button class="btn btn-sm btn-primary" type="submit"><?= icon('save') ?>حفظ</button>
            </form></details>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<!-- ================================================================ compatibility -->
<section class="tab-panel" data-panel="compat" <?= $tab !== 'compat' ? 'hidden' : '' ?>>
  <div class="glass card">
    <h2><?= icon('compare') ?>إضافة أو تحديث معلومة توافق</h2>
    <form method="post" action="<?= e($base . '/compat') ?>" class="inline-form"><?= csrf_field() ?>
      <label class="field"><span>الجهاز أو المحاكي *</span><input type="text" name="target" required maxlength="64" list="compat-targets" placeholder="PS5 (توافق رجعي)">
        <datalist id="compat-targets"><?php foreach (['PS5 (توافق رجعي)', 'PS4 Pro', 'PS4 Slim', 'PS3 (طرازات CECHA/CECHB)', 'PS3 Slim', 'PS2 Slim', 'PS2 Fat', 'PS1', 'PCSX2', 'RPCS3', 'DuckStation', 'PPSSPP'] as $t): ?><option value="<?= e($t) ?>"></option><?php endforeach; ?></datalist></label>
      <label class="field"><span>الحالة</span><select name="status"><?php foreach (compat_names() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>تاريخ الاختبار</span><input type="date" name="tested_at"></label>
      <label class="field" style="grid-column: span 2"><span>ملاحظات</span><input type="text" name="notes" maxlength="500"></label>
      <button class="btn btn-primary" type="submit"><?= icon('save') ?>حفظ</button>
    </form>
    <p class="muted" style="font-size:var(--fs-xs); margin-top:10px">إدخال جهاز موجود مسبقاً يحدّث حالته بدلاً من تكراره.</p>
  </div>
  <div class="glass card">
    <h2><?= icon('list') ?>جدول التوافق</h2>
    <?php if (!$g['compat']): ?><p class="muted">لا توجد معلومات توافق بعد.</p><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>الجهاز/المحاكي</th><th>الحالة</th><th>ملاحظات</th><th>الاختبار</th><th></th></tr></thead><tbody>
      <?php foreach ($g['compat'] as $c): ?><tr><td><?= e($c['target']) ?></td><td><span class="tag"><?= e(compat_names()[$c['status']] ?? '') ?></span></td><td class="muted"><?= e($c['notes'] ?? '') ?></td><td><?= e($c['tested_at'] ?? '—') ?></td>
        <td><form method="post" action="<?= e($base . '/compat/' . (int) $c['id'] . '/delete') ?>"><?= csrf_field() ?><button class="btn btn-xs btn-danger btn-icon" type="submit" aria-label="حذف"><?= icon('trash') ?></button></form></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
