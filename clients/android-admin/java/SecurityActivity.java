package com.upsl.admin;

import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;

import org.json.JSONObject;

import java.util.List;

/** Security overview: HTTPS, temporarily locked accounts and recent failed sign-ins. */
public class SecurityActivity extends Screen {
    @Override
    protected void build(Bundle saved) {
        setTitleText("الأمان");
        if (!require("security.manage")) {
            return;
        }
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
        api.get("/admin/security", null, new Api.Callback() {
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

    private void render(JSONObject s) {
        LinearLayout col = page();
        boolean https = Json.bool(s, "https") || session.server().startsWith("https://");
        LinearLayout c = Ui.card(this);
        LinearLayout top = Ui.hbox(this);
        top.addView(Ui.iconPlate(this, https ? R.drawable.ic_lock : R.drawable.ic_shield_alert, https ? Ui.OK : Ui.BAD, 46));
        top.addView(Ui.hspace(this, 12));
        LinearLayout t = Ui.vbox(this);
        t.addView(Ui.text(this, https ? "الاتصال مشفر (HTTPS)" : "الاتصال غير مشفر", 16, Ui.INK, true));
        t.addView(Ui.muted(this, https ? "كلمات المرور ورموز الدخول تمر مشفرة." : "فعّل شهادة HTTPS على الخادم ثم «فرض HTTPS» في ملف الإعداد."));
        top.addView(t, Ui.weight(1));
        c.addView(top);
        c.addView(Ui.space(this, 10));
        c.addView(Ui.small(this, "كلمات المرور محفوظة بخوارزمية Argon2id/bcrypt، ورموز API محفوظة كبصمات SHA-256، وكل مسار إداري يتحقق من الصلاحية."));
        if (session.can("settings.manage", "security.manage")) {
            c.addView(Ui.space(this, 10));
            c.addView(Ui.button(this, "إعدادات الأمان والرفع", R.drawable.ic_settings, Ui.BTN_SECONDARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    open(SettingsActivity.class);
                }
            }), Ui.fill());
        }
        col.addView(c);

        List<JSONObject> locked = Json.list(Json.arr(s, "locked_users"));
        LinearLayout lc = Ui.card(this);
        lc.addView(Ui.h2(this, "حسابات مقفلة مؤقتاً (" + locked.size() + ")"));
        if (locked.isEmpty()) {
            lc.addView(Ui.muted(this, "لا توجد حسابات مقفلة."));
        }
        for (final JSONObject u : locked) {
            LinearLayout r = Ui.row(this, Ui.iconPlate(this, R.drawable.ic_lock, Ui.WARN, 36), Json.str(u, "username", ""),
                    Json.str(u, "email", "") + "\nحتى " + Fmt.date(Json.str(u, "locked_until")), null);
            r.setOnClickListener(new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    open(UserActivity.class, "id", Json.num(u, "id", 0));
                }
            });
            lc.addView(r);
        }
        col.addView(lc);

        List<JSONObject> fails = Json.list(Json.arr(s, "recent_failures"));
        LinearLayout fc = Ui.card(this);
        fc.addView(Ui.h2(this, "آخر محاولات الدخول الفاشلة"));
        if (fails.isEmpty()) {
            fc.addView(Ui.muted(this, "لا توجد محاولات فاشلة مسجلة."));
        }
        for (JSONObject f : fails) {
            boolean lockedEvent = "auth.locked".equals(Json.str(f, "action"));
            fc.addView(Ui.kv(this, Fmt.ago(Json.str(f, "at")), (lockedEvent ? "قفل حساب: " : "") + (Json.str(f, "description") != null ? Json.str(f, "description") : "")
                    + (Json.str(f, "user") != null ? " (@" + Json.str(f, "user") + ")" : "") + "\n" + Fmt.ltr(Json.str(f, "ip", ""))));
        }
        col.addView(fc);
    }
}
