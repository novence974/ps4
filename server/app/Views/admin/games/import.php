<?php $labels = ['new' => ['جديدة', 'tag-ok'], 'duplicate' => ['مكررة في المكتبة', 'tag-warn'], 'duplicate_in_file' => ['مكررة داخل الملف', 'tag-warn'], 'invalid' => ['بها أخطاء', 'tag-bad']]; ?>
<div class="grid-2">
  <section class="glass card">
    <h2><?= icon('file-sheet') ?>رفع ملف CSV أو JSON</h2>
    <form method="post" action="<?= e(url('/admin/import')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
      <input type="file" name="file" accept=".csv,.json,text/csv,application/json" required>
      <p class="muted" style="font-size:var(--fs-sm)">ستظهر معاينة كاملة قبل الحفظ: التحقق من كل سطر، واكتشاف المكررات داخل المكتبة وداخل الملف. الحد الأقصى <?= App\Services\ImportService::MAX_ROWS ?> لعبة للملف.</p>
      <button class="btn btn-primary" type="submit"><?= icon('scan') ?>معاينة الاستيراد</button>
    </form>
  </section>
  <section class="glass card">
    <h2><?= icon('info') ?>صيغة الملف</h2>
    <p class="muted" style="font-size:var(--fs-sm)">الأعمدة: <span class="code-inline">title, platform, categories, description, release_year, developer, publisher, age_rating, size, languages, region, version, edition, rating, product_code, trailer_url, published, featured</span></p>
    <ul class="muted" style="font-size:var(--fs-sm); margin:0; padding-inline-start:18px">
      <li>platform: ps5 أو PS4 أو PlayStation 3…</li>
      <li>categories و languages: مفصولة بـ | مثل <span class="code-inline">action|rpg</span> و <span class="code-inline">ar|en</span></li>
      <li>size: بالبايت أو مثل <span class="code-inline">45.2 GB</span>، والتقييم من 0 إلى 10</li>
    </ul>
    <div class="form-actions"><a class="btn btn-sm" href="<?= e(url('/admin/import/template.csv')) ?>"><?= icon('download') ?>قالب CSV</a><a class="btn btn-sm" href="<?= e(url('/admin/import/template.json')) ?>"><?= icon('file-json') ?>قالب JSON</a></div>
  </section>
</div>
<?php if ($preview): $s = $preview['summary']; ?>
<section class="glass card">
  <h2><?= icon('scan') ?>معاينة: <span class="code-inline"><?= e($filename ?? '') ?></span></h2>
  <div class="chips" style="margin-bottom:14px">
    <span class="chip">الإجمالي: <b><?= (int) $s['total'] ?></b></span>
    <?php foreach ($labels as $k => [$l, $cls]): ?><span class="tag <?= $cls ?>"><?= $l ?>: <?= (int) $s[$k] ?></span><?php endforeach; ?>
  </div>
  <form method="post" action="<?= e(url('/admin/import/commit')) ?>" class="row" style="margin-bottom:16px"><?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <label class="field" style="min-width:260px"><span>التعامل مع الألعاب المكررة في المكتبة</span>
      <select name="on_duplicate"><option value="skip">تجاهلها</option><option value="update">تحديث بيانات الموجودة</option><option value="create">إضافتها كنسخة جديدة</option></select></label>
    <button class="btn btn-primary" type="submit" style="align-self:end" <?= ($s['new'] + $s['duplicate']) ? '' : 'disabled' ?>><?= icon('check') ?>تنفيذ الاستيراد</button>
    <span class="muted" style="align-self:end; font-size:var(--fs-sm)">الأسطر التي بها أخطاء أو المكررة داخل الملف تُتجاهل دائماً.</span>
  </form>
  <div class="table-wrap"><table class="table"><thead><tr><th>السطر</th><th>اللعبة</th><th>الجهاز</th><th>النتيجة</th><th>التفاصيل</th></tr></thead><tbody>
    <?php foreach (array_slice($preview['rows'], 0, 500) as $r): ?>
      <tr><td class="mono"><?= (int) $r['line'] ?></td><td><b><?= e($r['title'] ?: '(بدون اسم)') ?></b></td><td><?= e($r['platform']) ?></td><td><span class="tag <?= $labels[$r['status']][1] ?>"><?= $labels[$r['status']][0] ?></span></td>
        <td class="muted" style="font-size:var(--fs-xs)">
          <?php if ($r['errors']): ?><?= e(implode(' | ', $r['errors'])) ?><?php endif; ?>
          <?php if ($r['duplicate_of']): ?>تطابق <a href="<?= e(url('/admin/games/' . (int) $r['duplicate_of']['id'] . '/edit')) ?>">#<?= (int) $r['duplicate_of']['id'] ?> <?= e($r['duplicate_of']['title']) ?></a><?php endif; ?>
          <?php if ($r['same_as_line']): ?>نفس السطر <?= (int) $r['same_as_line'] ?><?php endif; ?>
        </td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php if (count($preview['rows']) > 500): ?><p class="muted">تُعرض أول 500 سطر فقط في المعاينة.</p><?php endif; ?>
</section>
<?php endif; ?>
