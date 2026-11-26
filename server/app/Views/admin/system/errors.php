<div class="toolbar">
  <div class="chips"><?php foreach (['' => 'الكل', 'error' => 'أخطاء', 'warning' => 'تحذيرات', 'info' => 'معلومات'] as $k => $l): ?><a class="chip" href="?level=<?= $k ?>" <?= $level === $k ? 'style="border-color:var(--violet)"' : '' ?>><?= $l ?></a><?php endforeach; ?></div>
  <span class="spacer"></span><span class="muted" style="font-size:var(--fs-sm)">من ملفات <span class="code-inline">storage/logs/app-*.log</span> — آخر 7 أيام</span>
</div>
<div class="glass table-wrap"><table class="table"><thead><tr><th>الوقت (UTC)</th><th>المستوى</th><th>الرسالة</th><th>المسار</th><th>IP</th></tr></thead><tbody>
  <?php foreach ($entries as $en): ?><tr>
    <td class="nowrap mono"><?= e($en['t'] ?? '') ?></td>
    <td><span class="tag log-level <?= ($en['level'] ?? '') === 'error' ? 'tag-bad' : (($en['level'] ?? '') === 'warning' ? 'tag-warn' : 'tag-info') ?>"><?= e($en['level'] ?? '') ?></span></td>
    <td><span class="ltr" style="overflow-wrap:anywhere"><?= e($en['msg'] ?? '') ?></span><?php if (!empty($en['ctx'])): ?><details><summary class="muted" style="font-size:var(--fs-xs)">التفاصيل</summary><pre class="json"><?= e(json_encode($en['ctx'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?></td>
    <td class="mono"><?= e(($en['method'] ?? '') . ' ' . ($en['path'] ?? '')) ?></td><td class="mono"><?= e($en['ip'] ?? '') ?></td></tr><?php endforeach; ?>
</tbody></table><?php if (!$entries): ?><?= partial('empty', ['icon' => 'badge', 'heading' => 'لا توجد أخطاء مسجلة', 'text' => 'كل شيء يعمل بسلام.']) ?><?php endif; ?></div>
