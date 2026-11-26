<?php
$page = (int) $result['page'];
$pages = (int) $result['pages'];
if ($pages <= 1) { return; }
$win = [];
for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) { $win[] = $i; }
?>
<nav class="pager" aria-label="الصفحات">
  <?php if ($page > 1): ?><a href="<?= e(query_with(['page' => $page - 1, 'partial' => null])) ?>" rel="prev" aria-label="الصفحة السابقة"><?= icon('chev-right') ?></a><?php endif; ?>
  <?php if ($win[0] > 1): ?><a href="<?= e(query_with(['page' => 1, 'partial' => null])) ?>">1</a><?php if ($win[0] > 2): ?><span class="gap">…</span><?php endif; ?><?php endif; ?>
  <?php foreach ($win as $i): ?>
    <?php if ($i === $page): ?><span class="on" aria-current="page"><?= $i ?></span><?php else: ?><a href="<?= e(query_with(['page' => $i, 'partial' => null])) ?>"><?= $i ?></a><?php endif; ?>
  <?php endforeach; ?>
  <?php if (end($win) < $pages): ?><?php if (end($win) < $pages - 1): ?><span class="gap">…</span><?php endif; ?><a href="<?= e(query_with(['page' => $pages, 'partial' => null])) ?>"><?= $pages ?></a><?php endif; ?>
  <?php if ($page < $pages): ?><a href="<?= e(query_with(['page' => $page + 1, 'partial' => null])) ?>" rel="next" aria-label="الصفحة التالية"><?= icon('chev-left') ?></a><?php endif; ?>
</nav>
