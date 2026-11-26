<?php $tabs = [['/account', 'user', 'نظرة عامة'], ['/account/favorites', 'heart', 'المفضلة'], ['/account/history', 'history', 'سجل المشاهدة'], ['/account/downloads', 'download', 'التنزيلات'], ['/account/updates', 'refresh', 'التحديثات'], ['/account/notifications', 'bell', 'الإشعارات'], ['/account/devices', 'phone', 'الأجهزة'], ['/account/edit', 'settings', 'الإعدادات']];
$cur = App\Core\Request::$current?->path(); ?>
<nav class="acc-tabs glass" aria-label="قائمة الحساب">
  <?php foreach ($tabs as [$href, $ic, $label]): ?><a href="<?= e(url($href)) ?>" class="<?= $cur === $href ? 'on' : '' ?>"><?= icon($ic) ?><?= e($label) ?></a><?php endforeach; ?>
</nav>
