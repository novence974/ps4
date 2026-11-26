<?php $f = static fn (string $k, $d = '') => old($k, $p[$k] ?? $d); $pid = $p ? (int) $p['id'] : 0; ?>
<div class="<?= $p ? 'dash' : '' ?>">
  <form method="post" action="<?= e(url($p ? '/admin/platforms/' . $pid : '/admin/platforms')) ?>" class="glass card"><?= csrf_field() ?>
    <h2><?= icon('gamepad') ?>بيانات الجهاز</h2>
    <div class="form-grid">
      <label class="field<?= field_error('name') ? ' has-error' : '' ?>"><span>الاسم *</span><input type="text" name="name" value="<?= e($f('name')) ?>" required maxlength="64"><?php if ($er = field_error('name')): ?><small class="error"><?= e($er) ?></small><?php endif; ?></label>
      <label class="field<?= field_error('short_name') ? ' has-error' : '' ?>"><span>الاسم المختصر *</span><input type="text" name="short_name" value="<?= e($f('short_name')) ?>" required maxlength="16" dir="ltr"></label>
      <label class="field<?= field_error('slug') ? ' has-error' : '' ?>"><span>المعرف في الروابط *</span><input type="text" name="slug" value="<?= e($f('slug')) ?>" required maxlength="32" dir="ltr" pattern="[a-z0-9]+(-[a-z0-9]+)*"><?php if ($er = field_error('slug')): ?><small class="error"><?= e($er) ?></small><?php endif; ?></label>
      <label class="field"><span>الشركة المصنعة</span><input type="text" name="manufacturer" value="<?= e($f('manufacturer', 'Sony Interactive Entertainment')) ?>" maxlength="64"></label>
      <label class="field"><span>سنة الإصدار</span><input type="number" name="release_year" value="<?= e($f('release_year')) ?>" min="1970" max="2100"></label>
      <label class="field"><span>الجيل</span><input type="number" name="generation" value="<?= e($f('generation')) ?>" min="1" max="20"></label>
      <label class="field"><span>لون الهوية</span><input type="color" name="accent_color" value="<?= e($f('accent_color', '#8b7cff')) ?>"></label>
      <label class="field"><span>الترتيب</span><input type="number" name="sort_order" value="<?= e($f('sort_order', 0)) ?>"></label>
      <label class="field full"><span>الوصف</span><textarea name="description" rows="3" maxlength="5000"><?= e($f('description')) ?></textarea></label>
      <label class="field full"><span>الصيغ المدعومة</span><input type="text" name="supported_formats" value="<?= e($f('supported_formats')) ?>" maxlength="255"></label>
      <label class="field full"><span>معلومات التوافق</span><textarea name="compatibility_notes" rows="3" maxlength="5000"><?= e($f('compatibility_notes')) ?></textarea></label>
      <label class="field full"><span>طرق التثبيت المسموح بها</span><textarea name="install_notes" rows="3" maxlength="5000"><?= e($f('install_notes')) ?></textarea><small class="hint">اذكر الطرق الرسمية فقط (المتجر، القرص، الإصدارات الرسمية)</small></label>
      <div class="full"><input type="hidden" name="is_active" value="0"><label class="switch"><span>ظاهر في الموقع والتطبيقات</span><input type="checkbox" name="is_active" value="1" <?= $f('is_active', 1) ? 'checked' : '' ?>></label></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('save') ?>حفظ</button><a class="btn btn-ghost" href="<?= e(url('/admin/platforms')) ?>">رجوع</a></div>
  </form>
  <?php if ($p): ?>
  <div>
    <section class="glass card"><h2><?= icon('image') ?>شعار الجهاز</h2>
      <div class="slab" style="--pc: <?= e($p['accent_color']) ?>; min-height:auto; aspect-ratio:1; width:150px; justify-content:center; align-items:center; margin-bottom:12px"><?php if ($p['logo_path']): ?><img src="<?= e(platform_logo($p)) ?>" alt="" style="width:80%"><?php else: ?><span class="glyph"><?= e($p['short_name']) ?></span><?php endif; ?></div>
      <form method="post" action="<?= e(url('/admin/platforms/' . $pid . '/image/logo')) ?>" enctype="multipart/form-data"><?= csrf_field() ?><input type="file" name="file" accept="image/png,image/webp,image/jpeg" required><p class="muted" style="font-size:var(--fs-xs)">PNG شفاف 512×512. لا تستخدم شعارات رسمية إلا إن كنت تملك حق استخدامها.</p><button class="btn btn-sm btn-primary" type="submit"><?= icon('upload') ?>رفع الشعار</button></form>
    </section>
    <section class="glass card"><h2><?= icon('image') ?>صورة الجهاز</h2>
      <div class="media-preview wide" style="margin-bottom:12px"><?php if ($p['image_path']): ?><img src="<?= e(media_url($p['image_path'], 'sm')) ?>" alt=""><?php endif; ?></div>
      <form method="post" action="<?= e(url('/admin/platforms/' . $pid . '/image/image')) ?>" enctype="multipart/form-data"><?= csrf_field() ?><input type="file" name="file" accept="image/png,image/webp,image/jpeg" required><div class="form-actions" style="margin-top:10px"><button class="btn btn-sm btn-primary" type="submit"><?= icon('upload') ?>رفع الصورة</button></div></form>
    </section>
  </div>
  <?php endif; ?>
</div>
