package com.upsl.admin;

import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;
import android.widget.TextView;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

/** Home: key numbers, 30-day downloads chart, every admin section (filtered by permission), platforms and disks. */
public class DashboardActivity extends Screen {
    private JSONObject health;

    @Override
    protected boolean showBack() {
        return false;
    }

    @Override
    protected void build(Bundle saved) {
        setTitleText("لوحة التحكم");
        addAction(R.drawable.ic_refresh, "تحديث", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                load();
            }
        });
        addAction(R.drawable.ic_user_cog, "حسابي", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                open(AccountActivity.class);
            }
        });
        load();
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (titleView == null) {
            return;
        }
        if (Transfers.get(this).hasActive()) {
            setTitleText("لوحة التحكم • رفع جارٍ");
        } else {
            setTitleText("لوحة التحكم");
        }
    }

    private void load() {
        showLoading();
        api.get("/admin/health", null, new Api.Callback() {
            @Override
            public void done(Api.Result h) {
                health = h.ok ? h.obj() : null;
                api.get("/admin/stats", null, new Api.Callback() {
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
        });
    }

    private void render(JSONObject s) {
        LinearLayout col = page();

        // greeting
        LinearLayout hello = Ui.card(this);
        LinearLayout top = Ui.hbox(this);
        LinearLayout who = Ui.vbox(this);
        who.addView(Ui.text(this, "مرحباً، " + session.displayName(), 18, Ui.INK, true));
        TextView host = Ui.small(this, Session.normalizeServer(session.server()).replace("https://", "").replace("http://", ""));
        Ui.ltrText(host);
        who.addView(host);
        top.addView(who, Ui.weight(1));
        top.addView(Ui.chip(this, session.roleName().length() > 0 ? session.roleName() : "مدير", Ui.ACCENT));
        hello.addView(top);
        if (health != null) {
            boolean maint = Json.bool(health, "maintenance");
            boolean dbOk = Json.bool(Json.obj(health, "database"), "ok");
            hello.addView(Ui.space(this, 10));
            LinearLayout chips = Ui.hbox(this);
            chips.addView(Ui.chip(this, dbOk ? "الخادم يعمل" : "مشكلة في قاعدة البيانات", dbOk ? Ui.OK : Ui.BAD));
            chips.addView(Ui.hspace(this, 6));
            if (maint) {
                chips.addView(Ui.chip(this, "وضع الصيانة مفعّل", Ui.WARN));
                chips.addView(Ui.hspace(this, 6));
            }
            chips.addView(Ui.chip(this, "v" + Json.str(health, "version", "?"), Ui.MIST));
            hello.addView(chips);
        }
        col.addView(hello);

        // numbers
        List<View> tiles = new ArrayList<View>();
        tiles.add(tile(R.drawable.ic_gamepad, Ui.ACCENT, Fmt.num(Json.lng(s, "games_total", 0)), "لعبة",
                Fmt.num(Json.lng(s, "games_published", 0)) + " منشورة • +" + Json.lng(s, "games_new_7d", 0) + " هذا الأسبوع", GamesActivity.class));
        tiles.add(tile(R.drawable.ic_users, Ui.ACCENT2, Fmt.num(Json.lng(s, "users_total", 0)), "مستخدم",
                "+" + Json.lng(s, "users_new_7d", 0) + " هذا الأسبوع • " + Json.lng(s, "active_users_24h", 0) + " نشط اليوم",
                session.can("users.view", "users.manage") ? UsersActivity.class : null));
        tiles.add(tile(R.drawable.ic_download, Ui.ACCENT3, Fmt.num(Json.lng(s, "downloads_24h", 0)), "تنزيل خلال 24 ساعة",
                "الإجمالي " + Fmt.num(Json.lng(s, "downloads_total", 0)), session.can("downloads.view") ? DownloadsActivity.class : null));
        tiles.add(tile(R.drawable.ic_files, Ui.OK, Fmt.num(Json.lng(s, "files_total", 0)), "ملف مستضاف",
                Fmt.bytes(Json.lng(s, "files_bytes", 0)), session.can("files.manage") ? FilesActivity.class : null));
        int active = Json.num(s, "uploads_active", 0) + Json.num(s, "uploads_processing", 0);
        tiles.add(tile(R.drawable.ic_cloud_upload, active > 0 ? Ui.WARN : Ui.MIST, String.valueOf(active), "رفع جارٍ",
                Json.num(s, "uploads_done_7d", 0) + " مكتمل • " + Json.num(s, "uploads_failed_7d", 0) + " فشل (7 أيام)",
                session.can("files.upload", "media.upload") ? UploadsActivity.class : null));
        tiles.add(tile(R.drawable.ic_heart, Ui.BAD, Fmt.num(Json.lng(s, "favorites_total", 0)), "إضافة للمفضلة", "عبر كل المستخدمين", null));
        col.addView(Ui.grid(this, tiles, 2));

        // downloads chart
        JSONArray daily = Json.arr(s, "downloads_daily");
        if (daily.length() > 0) {
            final List<JSONObject> days = Json.list(daily);
            long[] values = new long[days.size()];
            long sum = 0;
            for (int i = 0; i < days.size(); i++) {
                values[i] = Json.lng(days.get(i), "count", 0);
                sum += values[i];
            }
            LinearLayout chart = Ui.card(this);
            LinearLayout head = Ui.hbox(this);
            head.addView(Ui.text(this, "التنزيلات — آخر 30 يوماً", 15, Ui.INK, true), Ui.weight(1));
            head.addView(Ui.chip(this, Fmt.num(sum), Ui.ACCENT3));
            chart.addView(head);
            chart.addView(Ui.space(this, 8));
            chart.addView(new Charts.Area(this, values, new Charts.Label() {
                @Override
                public String at(int index) {
                    String d = Json.str(days.get(index), "date", "");
                    return d.length() >= 10 ? d.substring(5).replace('-', '/') : d;
                }
            }));
            chart.addView(Ui.small(this, "المس الرسم لعرض عدد التنزيلات في يوم محدد."));
            col.addView(chart);
        }

        // sections
        col.addView(Ui.h2(this, "الأقسام"));
        List<View> nav = new ArrayList<View>();
        addNav(nav, R.drawable.ic_gamepad, "الألعاب", GamesActivity.class, "admin.access");
        addNav(nav, R.drawable.ic_plus, "إضافة لعبة", GameFormActivity.class, "games.create");
        addNav(nav, R.drawable.ic_cloud_upload, "عمليات الرفع", UploadsActivity.class, "files.upload", "media.upload");
        addNav(nav, R.drawable.ic_files, "الملفات المستضافة", FilesActivity.class, "files.manage");
        addNav(nav, R.drawable.ic_tv, "الأجهزة", PlatformsActivity.class, "admin.access");
        addNav(nav, R.drawable.ic_tag, "التصنيفات", CategoriesActivity.class, "admin.access");
        addNav(nav, R.drawable.ic_file_sheet, "استيراد CSV/JSON", ImportGamesActivity.class, "games.import");
        addNav(nav, R.drawable.ic_compare, "الألعاب المكررة", DuplicatesActivity.class, "games.edit");
        addNav(nav, R.drawable.ic_users, "المستخدمون", UsersActivity.class, "users.view", "users.manage");
        addNav(nav, R.drawable.ic_shield, "الأدوار والصلاحيات", RolesActivity.class, "roles.assign");
        addNav(nav, R.drawable.ic_megaphone, "التحديثات والأخبار", UpdatesActivity.class, "updates.manage");
        addNav(nav, R.drawable.ic_bell, "الإشعارات", NotificationsActivity.class, "notifications.send");
        addNav(nav, R.drawable.ic_download, "سجل التنزيلات", DownloadsActivity.class, "downloads.view");
        addNav(nav, R.drawable.ic_drive, "التخزين", StorageActivity.class, "storage.manage");
        addNav(nav, R.drawable.ic_history, "السجلات", LogsActivity.class, "logs.view");
        addNav(nav, R.drawable.ic_archive, "النسخ الاحتياطي", BackupsActivity.class, "backups.manage");
        addNav(nav, R.drawable.ic_settings, "الإعدادات", SettingsActivity.class, "settings.manage", "security.manage", "api.manage", "backups.manage");
        addNav(nav, R.drawable.ic_key, "رموز API", TokensActivity.class, "api.manage");
        addNav(nav, R.drawable.ic_shield_alert, "الأمان", SecurityActivity.class, "security.manage");
        addNav(nav, R.drawable.ic_activity, "حالة النظام", SystemActivity.class, "admin.access");
        addNav(nav, R.drawable.ic_user_cog, "حسابي", AccountActivity.class, "admin.access");
        col.addView(Ui.grid(this, nav, 3));

        // top games
        List<JSONObject> topGames = Json.list(Json.arr(s, "top_games"));
        if (!topGames.isEmpty()) {
            col.addView(Ui.h2(this, "الأكثر تنزيلاً"));
            for (final JSONObject g : topGames) {
                LinearLayout row = Ui.row(this, Ui.iconPlate(this, R.drawable.ic_flame, Ui.WARN, 38), Json.str(g, "title", ""),
                        Fmt.num(Json.lng(g, "download_count", 0)) + " تنزيل • " + Fmt.num(Json.lng(g, "view_count", 0)) + " مشاهدة", null);
                row.setOnClickListener(new View.OnClickListener() {
                    @Override
                    public void onClick(View v) {
                        open(GameActivity.class, "id", Json.num(g, "id", 0));
                    }
                });
                col.addView(row);
            }
        }

        // platforms
        List<JSONObject> plats = Json.list(Json.arr(s, "by_platform"));
        if (!plats.isEmpty()) {
            LinearLayout card = Ui.card(this);
            card.addView(Ui.h2(this, "الألعاب حسب الجهاز"));
            long max = 1;
            for (JSONObject p : plats) {
                max = Math.max(max, Json.lng(p, "total", 0));
            }
            for (JSONObject p : plats) {
                long total = Json.lng(p, "total", 0);
                card.addView(Ui.meter(this, Json.str(p, "short_name", "?"), Fmt.num(Json.lng(p, "published", 0)) + " منشورة من " + Fmt.num(total),
                        total / (float) max, Ui.parseColor(Json.str(p, "accent_color"), Ui.ACCENT2)));
            }
            col.addView(card);
        }

        // disks
        List<JSONObject> disks = Json.list(Json.arr(s, "disks"));
        if (!disks.isEmpty()) {
            LinearLayout card = Ui.card(this);
            card.addView(Ui.h2(this, "مساحة التخزين"));
            for (JSONObject d : disks) {
                long total = Json.lng(d, "total", 0);
                long free = Json.lng(d, "free", 0);
                float used = total > 0 ? (total - free) / (float) total : 0;
                card.addView(Ui.meter(this, "القرص «" + Json.str(d, "name", "?") + "»", "متاح " + Fmt.bytes(free) + " من " + Fmt.bytes(total),
                        used, used > 0.9f ? Ui.BAD : used > 0.75f ? Ui.WARN : Ui.OK));
            }
            card.addView(Ui.small(this, "الوسائط (صور وفيديو): " + Fmt.bytes(Json.lng(s, "media_bytes", 0))));
            col.addView(card);
        }
    }

    private View tile(int icon, int color, String value, String label, String hint, final Class<?> target) {
        LinearLayout t = Ui.stat(this, icon, color, value, label, hint);
        if (target != null) {
            t.setBackground(Ui.ripple(this, Ui.glass(this, 18), 18));
            t.setOnClickListener(new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    open(target);
                }
            });
        }
        return t;
    }

    private void addNav(List<View> nav, int icon, String label, final Class<?> target, String... perms) {
        if (!session.can(perms)) {
            return;
        }
        LinearLayout t = Ui.vbox(this);
        t.setGravity(android.view.Gravity.CENTER_HORIZONTAL);
        t.setBackground(Ui.ripple(this, Ui.glass(this, 16), 16));
        t.setPadding(Ui.dp(this, 6), Ui.dp(this, 14), Ui.dp(this, 6), Ui.dp(this, 12));
        t.addView(Ui.iconPlate(this, icon, Ui.ACCENT2, 40));
        t.addView(Ui.space(this, 8));
        TextView l = Ui.text(this, label, 12.5f, Ui.INK, true);
        l.setTextAlignment(View.TEXT_ALIGNMENT_CENTER);
        l.setMaxLines(2);
        t.addView(l, Ui.fill());
        t.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                open(target);
            }
        });
        nav.add(t);
    }
}
