<div class="wrap">
  <?= App\Core\View::file('account/header', []) ?>
  <div class="section-head">
    <h2>الإشعارات <?php if ($result['unread']): ?><span class="tag tag-info"><?= (int) $result['unread'] ?> غير مقروء</span><?php endif; ?></h2>
    <?php if ($result['unread']): ?><form method="post" action="<?= e(url('/account/notifications/read')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= icon('check') ?>تعليم الكل كمقروء</button></form><?php endif; ?>
  </div>
  <div class="glass panel"><?= partial('notifications-list', ['items' => $result['items']]) ?></div>
  <?= partial('pagination', ['result' => $result]) ?>
</div>
