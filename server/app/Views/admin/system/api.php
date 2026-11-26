<div class="dash">
  <div>
    <form method="post" action="<?= e(url('/admin/api')) ?>" class="glass card"><?= csrf_field() ?>
      <h2><?= icon('server') ?>إعدادات واجهة API</h2>
      <input type="hidden" name="api_enabled" value="0"><label class="switch"><span>تفعيل API<small>عند التعطيل تتوقف تطبيقات الجوال وPS4 عن الاتصال (تبقى /status متاحة)</small></span><input type="checkbox" name="api_enabled" value="1" <?= setting('api.enabled', true) ? 'checked' : '' ?>></label>
      <div class="form-grid" style="margin-top:10px">
        <label class="field"><span>صلاحية رمز الدخول (يوم)</span><input type="number" name="api_token_ttl_days" value="<?= (int) setting('api.token_ttl_days', 30) ?>" min="1" max="3650"></label>
        <label class="field"><span>حد الطلبات في الدقيقة لكل مستخدم/عنوان</span><input type="number" name="api_rate_limit_per_minute" value="<?= (int) setting('api.rate_limit_per_minute', 120) ?>" min="10" max="100000"></label>
        <label class="field full"><span>نطاقات CORS المسموحة</span><input type="text" name="api_cors_origins" value="<?= e(setting('api.cors_origins', '')) ?>" dir="ltr" placeholder="https://app.example.com, https://other.example"><small class="hint">مطلوبة فقط لتطبيقات الويب على نطاق آخر. تطبيقات Android وPS4 لا تحتاجها. استخدم * للسماح للجميع (غير مستحسن).</small></label>
      </div>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('save') ?>حفظ</button></div>
    </form>
    <section class="glass card"><h2><?= icon('key') ?>رموز الوصول النشطة (<?= count($tokens) ?>)</h2>
      <?php if (!$tokens): ?><p class="muted">لا توجد أجهزة متصلة حالياً.</p><?php else: ?>
      <div class="table-wrap"><table class="table"><thead><tr><th>المستخدم</th><th>الجهاز</th><th>آخر استخدام</th><th>IP</th><th>ينتهي</th><th></th></tr></thead><tbody>
        <?php foreach ($tokens as $t): ?><tr><td><?= e($t['username']) ?></td><td><?= e($t['name']) ?></td><td><?= e(time_ago($t['last_used_at'])) ?></td><td class="mono"><?= e($t['last_used_ip'] ?? '') ?></td><td><?= e(fmt_date($t['expires_at'])) ?></td>
          <td><form method="post" action="<?= e(url('/admin/api/tokens/' . (int) $t['id'] . '/revoke')) ?>" data-confirm="إلغاء هذا الرمز؟ سيُطلب من الجهاز تسجيل الدخول مجدداً."><?= csrf_field() ?><button class="btn btn-xs btn-danger" type="submit">إلغاء</button></form></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </section>
  </div>
  <div>
    <section class="glass card"><h2><?= icon('link') ?>بيانات الربط للتطبيقات</h2>
      <dl class="kv"><dt>عنوان الخادم</dt><dd class="code-inline"><?= e(rtrim(abs_url(''), '/')) ?></dd><dt>مسار API</dt><dd class="code-inline"><?= e(abs_url('api/v1')) ?></dd><dt>المصادقة</dt><dd><span class="code-inline">Authorization: Bearer &lt;token&gt;</span></dd><dt>حجم جزء الرفع</dt><dd><?= e(human_bytes(App\Services\UploadService::chunkSize())) ?></dd></dl>
      <p class="muted" style="font-size:var(--fs-xs)">التوثيق الكامل في <span class="code-inline">docs/03-API.md</span> ومجموعة Postman في <span class="code-inline">docs/postman_collection.json</span>.</p>
    </section>
    <section class="glass card"><h2><?= icon('terminal') ?>تجربة طلب</h2>
      <form data-api-console class="stack">
        <label class="field"><span>المسار (GET)</span><input type="text" name="path" value="/api/v1/status" dir="ltr"></label>
        <div class="row"><button class="btn btn-sm btn-primary" type="submit"><?= icon('play') ?>إرسال</button><span class="muted" data-api-meta style="font-size:var(--fs-xs)"></span></div>
        <pre class="json" data-api-out>{}</pre>
      </form>
    </section>
  </div>
</div>
