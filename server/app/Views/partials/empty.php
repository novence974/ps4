<div class="empty">
  <?= icon($icon ?? 'disc') ?>
  <h3><?= e($heading) ?></h3>
  <?php if (!empty($text)): ?><p><?= e($text) ?></p><?php endif; ?>
  <?php if (!empty($action)): ?><a class="btn btn-primary" href="<?= e(url($action[0])) ?>"><?= e($action[1]) ?></a><?php endif; ?>
</div>
