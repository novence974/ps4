package com.upsl.admin;

import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;

import org.json.JSONArray;
import org.json.JSONObject;

/** Server health: versions, database, cache, background queue, cron, PHP limits and extensions. */
public class SystemActivity extends Screen {
    @Override
    protected void build(Bundle saved) {
        setTitleText("حالة النظام");
        addAction(R.drawable.ic_refresh, "تحديث", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                load();
            }
        });
        load();
    }

    private void load() {
        showLoading();
        api.get("/admin/health", null, new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                if (!alive()) {
                    return;
                }
                if (!r.ok) {
                    showError(r.error, new Runnable() {
                        @Override
                        public void run() {
                            load();
                        }
                    });
                    return;
                }
                render(r.obj());
            }
        });
    }

    private void render(JSONObject h) {
        LinearLayout col = page();
        boolean ok = "ok".equals(Json.str(h, "status"));
        LinearLayout head = Ui.card(this);
        LinearLayout top = Ui.hbox(this);
        top.addView(Ui.iconPlate(this, ok ? R.drawable.ic_ok : R.drawable.ic_err, ok ? Ui.OK : Ui.BAD, 48));
        top.addView(Ui.hspace(this, 12));
        LinearLayout t = Ui.vbox(this);
        t.addView(Ui.text(this, ok ? "الخادم يعمل بشكل طبيعي" : "الخدمة متدهورة", 17, Ui.INK, true));
        t.addView(Ui.muted(this, Json.str(h, "app", "") + " — الإصدار " + Json.str(h, "version", "?") + " — API " + Json.str(h, "api_version", "v1")));
        top.addView(t, Ui.weight(1));
        head.addView(top);
        if (Json.bool(h, "maintenance")) {
            head.addView(Ui.space(this, 10));
            head.addView(Ui.chip(this, "وضع الصيانة مفعّل: الزوار يرون صفحة الصيانة", Ui.WARN));
        }
        col.addView(head);

        LinearLayout srv = Ui.card(this);
        srv.addView(Ui.h2(this, "الخادم"));
        JSONObject db = Json.obj(h, "database");
        srv.addView(Ui.kv(this, "قاعدة البيانات", (Json.bool(db, "ok") ? "متصلة" : "غير متصلة") + " — " + Json.str(h, "db_version", "") + " (" + Json.str(db, "latency_ms", "?") + " ms)"));
        srv.addView(Ui.kv(this, "PHP", Json.str(h, "php")));
        srv.addView(Ui.kv(this, "خادم الويب", Json.str(h, "server")));
        srv.addView(Ui.kv(this, "الكاش", Json.str(h, "cache")));
        srv.addView(Ui.kv(this, "دعم WebP", Json.bool(h, "webp") ? "نعم" : "لا (تُحفظ الصور JPG/PNG)"));
        srv.addView(Ui.kv(this, "حجم جزء الرفع", Fmt.bytes(Json.lng(h, "chunk_size", 0))));
        JSONArray load = h.optJSONArray("load");
        if (load != null && load.length() >= 3) {
            srv.addView(Ui.kv(this, "الحمل (1/5/15 د)", String.format(java.util.Locale.US, "%.2f / %.2f / %.2f", load.optDouble(0), load.optDouble(1), load.optDouble(2))));
        }
        srv.addView(Ui.kv(this, "آخر تشغيل للمهام المجدولة", Json.str(h, "last_cron") == null || Json.str(h, "last_cron").length() == 0 ? "لم تعمل بعد — اضبط cron" : Json.str(h, "last_cron")));
        srv.addView(Ui.kv(this, "آخر نسخة احتياطية", Json.str(h, "last_backup") == null || Json.str(h, "last_backup").length() == 0 ? "—" : Json.str(h, "last_backup")));
        col.addView(srv);

        JSONObject q = Json.obj(h, "queue");
        LinearLayout qc = Ui.card(this);
        qc.addView(Ui.h2(this, "طابور المهام الخلفية"));
        qc.addView(Ui.kv(this, "في الانتظار", String.valueOf(Json.num(q, "pending", 0))));
        qc.addView(Ui.kv(this, "قيد التنفيذ", String.valueOf(Json.num(q, "running", 0))));
        qc.addView(Ui.kv(this, "منجزة", String.valueOf(Json.num(q, "done", 0))));
        qc.addView(Ui.kv(this, "فاشلة", String.valueOf(Json.num(q, "failed", 0))));
        qc.addView(Ui.small(this, "تجميع الملفات الكبيرة بعد رفعها يتم في هذا الطابور. إن لم يكن العامل (bin/worker.php) أو cron مفعّلاً يمكنك تشغيله يدوياً."));
        if (session.can("files.upload", "settings.manage")) {
            qc.addView(Ui.space(this, 10));
            qc.addView(Ui.button(this, "تشغيل الطابور الآن", R.drawable.ic_play, Ui.BTN_SECONDARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    busy("جارٍ تنفيذ المهام المنتظرة (حتى دقيقة)…");
                    api.post("/admin/queue/run", new JSONObject(), new Api.Callback() {
                        @Override
                        public void done(Api.Result r) {
                            busy(null);
                            if (!alive()) {
                                return;
                            }
                            if (r.ok) {
                                toast("نُفذت " + Json.num(r.obj(), "done", 0) + " مهمة، وفشلت " + Json.num(r.obj(), "failed", 0));
                                load();
                            } else {
                                fail(r);
                            }
                        }
                    });
                }
            }), Ui.fill());
        }
        col.addView(qc);

        JSONObject limits = Json.obj(h, "limits");
        if (limits != null) {
            LinearLayout lc = Ui.card(this);
            lc.addView(Ui.h2(this, "حدود PHP"));
            lc.addView(Ui.kv(this, "upload_max_filesize", Json.str(limits, "upload_max_filesize")));
            lc.addView(Ui.kv(this, "post_max_size", Json.str(limits, "post_max_size")));
            lc.addView(Ui.kv(this, "memory_limit", Json.str(limits, "memory_limit")));
            lc.addView(Ui.kv(this, "max_execution_time", Json.str(limits, "max_execution_time")));
            col.addView(lc);
        }
        JSONObject ext = Json.obj(h, "extensions");
        if (ext != null) {
            LinearLayout ec = Ui.card(this);
            ec.addView(Ui.h2(this, "إضافات PHP"));
            LinearLayout wrap = Ui.vbox(this);
            LinearLayout line = null;
            int n = 0;
            for (String k : Json.keys(ext)) {
                if (n % 3 == 0) {
                    line = Ui.hbox(this);
                    line.setPadding(0, Ui.dp(this, 3), 0, Ui.dp(this, 3));
                    wrap.addView(line);
                }
                boolean on = Json.bool(ext, k);
                line.addView(Ui.chip(this, (on ? "✓ " : "✗ ") + k, on ? Ui.OK : Ui.FAINT));
                line.addView(Ui.hspace(this, 6));
                n++;
            }
            ec.addView(wrap);
            col.addView(ec);
        }
        for (JSONObject d : Json.list(Json.arr(h, "disks"))) {
            long total = Json.lng(d, "total", 0);
            long free = Json.lng(d, "free", 0);
            float used = total > 0 ? (total - free) / (float) total : 0;
            col.addView(Ui.meter(this, "القرص «" + Json.str(d, "name", "") + "»", "متاح " + Fmt.bytes(free), used, used > 0.9f ? Ui.BAD : Ui.OK));
        }
    }
}
