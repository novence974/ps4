<?php
/** @var array $stats @var array $health @var array $activity */
$s = $stats;
$maxDaily = max(1, max(array_column($s['downloads_daily'], 'count')));
$maxPlat = max(1, max(array_map('intval', array_column($s['by_platform'], 'total')) ?: [1]));
$disk = $s['disks'][0] ?? null;
$q = $health['queue'];
?>
<div class="tiles">
  <div class="tile"><span class="ico"><?= icon('library') ?></span><b><?= number_format($s['games_total']) ?></b><span>إجمالي الألعاب</span><small><?= number_format($s['games_published']) ?> منشورة — <?= number_format($s['games_new_7d']) ?> جديدة هذا الأسبوع</small></div>
  <div class="tile t-azure"><span class="ico"><?= icon('users') ?></span><b><?= number_format($s['users_total']) ?></b><span>المستخدمون</span><small><?= number_format($s['users_new_7d']) ?> جديد هذا الأسبوع — <?= number_format($s['active_users_24h']) ?> نشط اليوم</small></div>
  <div class="tile t-aqua"><span class="ico"><?= icon('download') ?></span><b><?= number_format($s['downloads_total']) ?></b><span>التنزيلات</span><small><?= number_format($s['downloads_24h']) ?> خلال 24 ساعة</small></div>
  <div class="tile t-rose"><span class="ico"><?= icon('drive') ?></span><b class="ltr"><?= e(human_bytes($s['files_bytes'])) ?></b><span>حجم ملفات المكتبة</span><small><?= number_format($s['files_total']) ?> ملف — الوسائط <?= e(human_bytes($s['media_bytes'])) ?></small></div>
  <div class="tile t-ok"><span class="ico"><?= icon('cloud-upload') ?></span><b><?= number_format($s['uploads_done_7d']) ?></b><span>عمليات رفع مكتملة (7 أيام)</span><small><?= (int) $s['uploads_active'] ?> جارية — <?= (int) $s['uploads_processing'] ?> قيد المعالجة — <?= (int) $s['uploads_failed_7d'] ?> فشلت</small></div>
  <div class="tile"><span class="ico"><?= icon('files') ?></span><b><?= number_format($s['files_new_7d']) ?></b><span>ملفات جديدة (7 أيام)</span><small><?= number_format($s['favorites_total']) ?> إضافة إلى المفضلة</small></div>
</div>

<div class="dash">
  <div>
    <section class="glass card">
      <h2><?= icon('chart') ?>التنزيلات — آخر 30 يوماً<span class="spacer"></span><a class="btn btn-xs" href="<?= e(url('/admin/downloads')) ?>">السجل</a></h2>
      <div class="bars" role="img" aria-label="رسم بياني للتنزيلات اليومية">
        <?php foreach ($s['downloads_daily'] as $d): ?><span class="b" data-v="<?= (int) $d['count'] ?>" style="height: <?= max(2, round($d['count'] / $maxDaily * 100)) ?>%" title="<?= e($d['date'] . ': ' . $d['count']) ?>"></span><?php endforeach; ?>
      </div>
      <div class="row muted" style="justify-content:space-between; font-size:var(--fs-xs); direction:ltr"><span><?= e($s['downloads_daily'][0]['date']) ?></span><span><?= e(end($s['downloads_daily'])['date']) ?></span></div>
    </section>
    <section class="glass card">
      <h2><?= icon('gamepad') ?>الألعاب حسب الجهاز</h2>
      <div class="hbars">
        <?php foreach ($s['by_platform'] as $p): ?>
          <div class="hbar" style="--pc: <?= e($p['accent_color']) ?>"><b class="ltr"><?= e($p['short_name']) ?></b><span class="track"><i style="width: <?= round(((int) $p['total']) / $maxPlat * 100, 1) ?>%"></i></span><span class="muted"><?= (int) $p['total'] ?> (<?= (int) $p['published'] ?> منشورة)</span></div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php if ($activity): ?>
    <section class="glass card">
      <h2><?= icon('activity') ?>نشاط المستخدمين<span class="spacer"></span><a class="btn btn-xs" href="<?= e(url('/admin/logs')) ?>">السجل الكامل</a></h2>
      <ul class="list">
        <?php foreach ($activity as $a): ?>
          <li><span class="tag"><?= e(App\Services\ActivityLog::label($a['action'])) ?></span><div class="grow"><small><?= e($a['description'] ?? '') ?></small></div><small><?= e($a['username'] ?? 'النظام') ?> — <?= e(time_ago($a['created_at'])) ?></small></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  </div>
  <div>
    <section class="glass card">
      <h2><?= icon('server') ?>حالة الخادم<span class="spacer"></span><span class="tag <?= $health['status'] === 'ok' ? 'tag-ok' : 'tag-bad' ?>"><?= $health['status'] === 'ok' ? 'يعمل' : 'مشكلة' ?></span></h2>
      <div class="health" data-health>
        <div><span>قاعدة البيانات</span><b><?= $health['database']['ok'] ? 'متصلة' : 'غير متصلة' ?> — <span class="ltr"><?= e((string) $health['database']['latency_ms']) ?> ms</span></b></div>
        <div><span>PHP</span><b class="ltr"><?= e($health['php']) ?></b></div>
        <div><span>الكاش</span><b class="ltr"><?= e($health['cache']) ?></b></div>
        <div><span>طابور المهام</span><b><?= (int) $q['pending'] ?> بانتظار — <?= (int) $q['running'] ?> تعمل — <?= (int) $q['failed'] ?> فشلت</b></div>
        <div><span>آخر تشغيل لـ Cron</span><b><?= $health['last_cron'] ? e(time_ago($health['last_cron'])) : '<span class="tag tag-warn">لم يُضبط بعد</span>' ?></b></div>
        <div><span>آخر نسخة احتياطية</span><b><?= $health['last_backup'] ? e(time_ago($health['last_backup'])) : '—' ?></b></div>
        <div><span>WebP</span><b><?= $health['webp'] ? 'مدعوم' : 'غير مدعوم' ?></b></div>
      </div>
      <?php if (can('files.upload') || can('settings.manage')): ?>
        <form method="post" action="<?= e(url('/admin/queue/run')) ?>" style="margin-top:12px"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= icon('refresh') ?>تشغيل طابور المهام الآن</button></form>
      <?php endif; ?>
    </section>
    <?php if ($disk): $used = $disk['total'] ? ($disk['total'] - $disk['free']) / $disk['total'] * 100 : 0; ?>
    <section class="glass card">
      <h2><?= icon('drive') ?>مساحة التخزين</h2>
      <?php foreach ($s['disks'] as $d): $pct = $d['total'] ? ($d['total'] - $d['free']) / $d['total'] * 100 : 0; ?>
        <div style="margin-bottom:12px">
          <div class="row" style="justify-content:space-between; font-size:var(--fs-sm)"><b class="ltr"><?= e($d['name']) ?></b><span class="muted">المتاح <span class="ltr"><?= e(human_bytes($d['free'])) ?></span> من <span class="ltr"><?= e(human_bytes($d['total'])) ?></span></span></div>
          <div class="meter <?= $pct > 90 ? 'bad' : ($pct > 75 ? 'warn' : '') ?>" style="margin-top:6px"><i style="width: <?= round($pct, 1) ?>%"></i></div>
          <small class="muted">المكتبة: <?= e(human_bytes($d['library_bytes'])) ?> في <?= (int) $d['library_files'] ?> <?= ar_noun((int) $d['library_files'], 'ملف', 'ملفات') ?></small>
        </div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <section class="glass card">
      <h2><?= icon('flame') ?>الأكثر تحميلاً</h2>
      <?php if ($s['top_games']): ?>
      <ul class="list">
        <?php foreach ($s['top_games'] as $g): ?><li><div class="grow"><a href="<?= e(url('/admin/games/' . (int) $g['id'] . '/edit')) ?>"><b><?= e($g['title']) ?></b></a></div><small><?= number_format((int) $g['download_count']) ?> تنزيل — <?= number_format((int) $g['view_count']) ?> مشاهدة</small></li><?php endforeach; ?>
      </ul>
      <?php else: ?><p class="muted">لا توجد بيانات بعد.</p><?php endif; ?>
    </section>
    <section class="glass card">
      <h2><?= icon('users') ?>المستخدمون حسب الدور</h2>
      <div class="chips"><?php foreach ($s['by_role'] as $r): ?><span class="chip"><?= e($r['name']) ?>: <b><?= (int) $r['total'] ?></b></span><?php endforeach; ?></div>
    </section>
  </div>
</div>
