<?php $u = auth_user(); ?>
<div class="profile-head glass sheen">
  <img class="avatar" src="<?= e(avatar_url($u)) ?>" alt="" width="88" height="88">
  <div>
    <h1><?= e(user_label($u)) ?></h1>
    <span class="muted ltr">@<?= e($u['username']) ?></span>
    <div class="chips" style="margin-top:8px">
      <span class="tag tag-info"><?= e($u['role_name']) ?></span>
      <?php if ($u['email_verified_at']): ?><span class="tag tag-ok"><?= icon('badge') ?>بريد مؤكد</span><?php else: ?><span class="tag tag-warn">البريد غير مؤكد</span><?php endif; ?>
      <span class="tag">عضو منذ <?= e(fmt_date($u['created_at'], 'Y/m')) ?></span>
    </div>
  </div>
  <?php if (!empty($stats)): ?>
  <div class="stats-row">
    <a class="stat" href="<?= e(url('/account/favorites')) ?>"><b><?= (int) $stats['favorites'] ?></b><span>في المفضلة</span></a>
    <a class="stat" href="<?= e(url('/account/downloads')) ?>"><b><?= (int) $stats['downloads'] ?></b><span>تنزيل</span></a>
    <a class="stat" href="<?= e(url('/account/history')) ?>"><b><?= (int) $stats['viewed'] ?></b><span><?= ar_noun((int) $stats['viewed'], 'لعبة شاهدتها', 'ألعاب شاهدتها') ?></span></a>
  </div>
  <?php endif; ?>
</div>
<?php if (!$u['email_verified_at'] && setting('auth.email_verification', true)): ?>
  <div class="notice warn" style="margin-top:14px"><?= icon('mail') ?>
    <span style="flex:1">أكّد بريدك <b class="ltr"><?= e($u['email']) ?></b> لتتمكن من تنزيل الملفات المخصصة للحسابات المؤكدة.</span>
    <form method="post" action="<?= e(url('/verify-email/resend')) ?>" data-ajax><?= csrf_field() ?><button class="btn btn-xs" type="submit">إعادة إرسال الرابط</button></form>
  </div>
<?php endif; ?>
<?= partial('account-nav') ?>
