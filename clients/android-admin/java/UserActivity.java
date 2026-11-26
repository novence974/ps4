package com.upsl.admin;

import android.os.Bundle;
import android.text.InputType;
import android.view.View;
import android.widget.LinearLayout;
import android.widget.TextView;

import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

/** One account: profile, devices, recent downloads and activity; role, status, download right, lock and deletion. */
public class UserActivity extends Screen {
    private int id;
    private JSONObject user;

    @Override
    protected void build(Bundle saved) {
        id = intExtra("id");
        setTitleText("مستخدم");
        if (!require("users.view", "users.manage")) {
            return;
        }
        load();
    }

    private void load() {
        if (user == null) {
            showLoading();
        }
        api.get("/admin/users/" + id, null, new Api.Callback() {
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
                user = r.obj();
                setTitleText(Json.str(user, "display_name", "مستخدم"));
                render();
            }
        });
    }

    private void render() {
        LinearLayout col = page();
        boolean self = id == session.userId();
        boolean manage = session.can("users.manage") && !self;
        JSONObject role = Json.obj(user, "role");

        LinearLayout head = Ui.card(this);
        LinearLayout top = Ui.hbox(this);
        String avatar = ImageLoader.pick(Json.obj(user, "avatar"), "sm", "sm_jpg");
        top.addView(avatar != null ? Ui.remote(this, avatar, 64, 64, 32) : Ui.iconPlate(this, R.drawable.ic_user, Ui.ACCENT2, 64));
        top.addView(Ui.hspace(this, 14));
        LinearLayout who = Ui.vbox(this);
        who.addView(Ui.text(this, Json.str(user, "display_name", ""), 18, Ui.INK, true));
        TextView handle = Ui.muted(this, "@" + Json.str(user, "username", ""));
        Ui.ltrText(handle);
        who.addView(handle);
        who.addView(Ui.space(this, 6));
        LinearLayout chips = Ui.hbox(this);
        chips.addView(Ui.chip(this, Json.str(role, "name", "?"), Ui.ACCENT));
        chips.addView(Ui.hspace(this, 6));
        boolean suspended = "suspended".equals(Json.str(user, "status"));
        chips.addView(Ui.chip(this, suspended ? "موقوف" : "نشط", suspended ? Ui.BAD : Ui.OK));
        if (Json.bool(user, "locked")) {
            chips.addView(Ui.hspace(this, 6));
            chips.addView(Ui.chip(this, "مقفل مؤقتاً", Ui.WARN));
        }
        who.addView(chips);
        top.addView(who, Ui.weight(1));
        head.addView(top);
        head.addView(Ui.space(this, 10));
        head.addView(Ui.kv(this, "البريد", Json.str(user, "email", "") + (Json.bool(user, "email_verified") ? "  ✓ مؤكد" : "  (غير مؤكد)")));
        head.addView(Ui.kv(this, "التنزيل", Json.bool(user, "can_download") ? "مسموح" : "ممنوع"));
        head.addView(Ui.kv(this, "تاريخ التسجيل", Fmt.date(Json.str(user, "created_at"))));
        head.addView(Ui.kv(this, "آخر دخول", Json.str(user, "last_login_at") == null ? "—" : Fmt.date(Json.str(user, "last_login_at"))
                + (Json.str(user, "last_login_ip") != null ? " — " + Fmt.ltr(Json.str(user, "last_login_ip")) : "")));
        if (Json.str(user, "bio") != null) {
            head.addView(Ui.kv(this, "نبذة", Json.str(user, "bio")));
        }
        JSONObject stats = Json.obj(user, "stats");
        head.addView(Ui.kv(this, "النشاط", Fmt.num(Json.lng(stats, "downloads", 0)) + " تنزيل • " + Fmt.num(Json.lng(stats, "favorites", 0)) + " مفضلة • "
                + Fmt.num(Json.lng(stats, "viewed", 0)) + " مشاهدة"));
        col.addView(head);

        if (self) {
            col.addView(Ui.small(this, "هذا حسابك. لتعديل بياناتك افتح «حسابي». لا يمكن تغيير دورك أو حالتك من هنا."));
            col.addView(Ui.space(this, 8));
        }

        if (manage) {
            LinearLayout actions = Ui.card(this);
            actions.addView(Ui.h2(this, "الإدارة"));
            List<View> b = new ArrayList<View>();
            if (session.can("roles.assign")) {
                b.add(Ui.button(this, "تغيير الدور", R.drawable.ic_shield, Ui.BTN_SECONDARY, new View.OnClickListener() {
                    @Override
                    public void onClick(View v) {
                        changeRole();
                    }
                }));
            }
            b.add(Ui.button(this, suspended ? "تفعيل الحساب" : "إيقاف الحساب", suspended ? R.drawable.ic_check : R.drawable.ic_ban,
                    suspended ? Ui.BTN_SECONDARY : Ui.BTN_DANGER, new View.OnClickListener() {
                        @Override
                        public void onClick(View v) {
                            final boolean susp = "suspended".equals(Json.str(user, "status"));
                            if (susp) {
                                update(Json.of("status", "active"), "فُعّل الحساب");
                            } else {
                                Dialogs.confirm(UserActivity.this, "إيقاف الحساب", "لن يستطيع صاحب الحساب تسجيل الدخول، وستُلغى كل رموز الدخول على أجهزته.",
                                        "إيقاف", true, new Runnable() {
                                            @Override
                                            public void run() {
                                                update(Json.of("status", "suspended"), "أُوقف الحساب");
                                            }
                                        });
                            }
                        }
                    }));
            final boolean canDl = Json.bool(user, "can_download");
            b.add(Ui.button(this, canDl ? "منع التنزيل" : "السماح بالتنزيل", R.drawable.ic_download, Ui.BTN_SECONDARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    update(Json.of("can_download", !canDl), canDl ? "مُنع التنزيل" : "سُمح بالتنزيل");
                }
            }));
            final boolean verified = Json.bool(user, "email_verified");
            b.add(Ui.button(this, verified ? "إلغاء تأكيد البريد" : "تأكيد البريد", R.drawable.ic_mail_check, Ui.BTN_SECONDARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    update(Json.of("email_verified", !verified), "تم الحفظ");
                }
            }));
            b.add(Ui.button(this, "تعديل الاسم الظاهر", R.drawable.ic_edit, Ui.BTN_SECONDARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    Dialogs.prompt(UserActivity.this, "الاسم الظاهر", "الاسم", Json.str(user, "display_name", ""), InputType.TYPE_CLASS_TEXT, new Ui.Callback<String>() {
                        @Override
                        public void done(String value) {
                            update(Json.of("display_name", value.length() == 0 ? null : value), "تم الحفظ");
                        }
                    });
                }
            }));
            if (Json.bool(user, "locked")) {
                b.add(Ui.button(this, "فك القفل", R.drawable.ic_lock, Ui.BTN_SECONDARY, new View.OnClickListener() {
                    @Override
                    public void onClick(View v) {
                        update(Json.of("unlock", true), "فُك قفل الحساب");
                    }
                }));
            }
            actions.addView(Ui.grid(this, b, 2));
            actions.addView(Ui.small(this, "لا يمكنك إدارة حساب بمستوى دور مساوٍ لدورك أو أعلى (إلا المدير الرئيسي)."));
            col.addView(actions);
        }
        if (session.can("notifications.send")) {
            col.addView(Ui.button(this, "إرسال إشعار لهذا المستخدم", R.drawable.ic_send, Ui.BTN_SECONDARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    NotificationsActivity.compose(UserActivity.this, String.valueOf(id), null);
                }
            }), Ui.fill());
            col.addView(Ui.space(this, 12));
        }

        List<JSONObject> devices = Json.list(Json.arr(user, "devices"));
        LinearLayout dv = Ui.card(this);
        dv.addView(Ui.h2(this, "الأجهزة المسجلة (" + devices.size() + ")"));
        if (devices.isEmpty()) {
            dv.addView(Ui.muted(this, "لا توجد رموز دخول نشطة (تطبيقات أو أجهزة)."));
        }
        for (JSONObject d : devices) {
            dv.addView(Ui.kv(this, Json.str(d, "name", "جهاز"), (Json.str(d, "last_used_at") != null ? "آخر استخدام " + Fmt.ago(Json.str(d, "last_used_at")) : "لم يُستخدم")
                    + (Json.str(d, "last_used_ip") != null ? " — " + Fmt.ltr(Json.str(d, "last_used_ip")) : "")));
        }
        if (!devices.isEmpty() && session.can("api.manage")) {
            dv.addView(Ui.small(this, "لإلغاء رمز جهاز افتح «رموز API»."));
        }
        col.addView(dv);

        List<JSONObject> downloads = Json.list(Json.arr(user, "recent_downloads"));
        LinearLayout dl = Ui.card(this);
        dl.addView(Ui.h2(this, "آخر التنزيلات"));
        if (downloads.isEmpty()) {
            dl.addView(Ui.muted(this, "لا توجد تنزيلات."));
        }
        for (JSONObject d : downloads) {
            dl.addView(Ui.kv(this, Fmt.ago(Json.str(d, "at")), Json.str(Json.obj(d, "game"), "title", "") + (Json.str(d, "file") != null ? "\n" + Json.str(d, "file") : "")));
        }
        col.addView(dl);

        List<JSONObject> activity = Json.list(Json.arr(user, "recent_activity"));
        LinearLayout ac = Ui.card(this);
        ac.addView(Ui.h2(this, "آخر النشاطات"));
        if (activity.isEmpty()) {
            ac.addView(Ui.muted(this, "لا يوجد نشاط مسجل."));
        }
        for (JSONObject a : activity) {
            ac.addView(Ui.kv(this, Fmt.ago(Json.str(a, "at")), Json.str(a, "label", Json.str(a, "action", "")) + (Json.str(a, "description") != null ? "\n" + Json.str(a, "description") : "")
                    + (Json.str(a, "ip") != null ? "\n" + Fmt.ltr(Json.str(a, "ip")) : "")));
        }
        col.addView(ac);

        if (manage) {
            col.addView(Ui.button(this, "حذف الحساب نهائياً", R.drawable.ic_trash, Ui.BTN_DANGER, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    Dialogs.confirm(UserActivity.this, "حذف الحساب", "سيُحذف حساب «" + Json.str(user, "username", "") + "» مع مفضلته وسجله. لا يمكن التراجع.",
                            "حذف نهائي", true, new Runnable() {
                                @Override
                                public void run() {
                                    busy("جارٍ الحذف…");
                                    api.delete("/admin/users/" + id, new Api.Callback() {
                                        @Override
                                        public void done(Api.Result r) {
                                            busy(null);
                                            if (!alive()) {
                                                return;
                                            }
                                            if (r.ok) {
                                                markDirty("users");
                                                toast("حُذف الحساب");
                                                finish();
                                            } else {
                                                fail(r);
                                            }
                                        }
                                    });
                                }
                            });
                }
            }), Ui.fill());
        }
    }

    private void update(JSONObject body, final String okMessage) {
        busy("جارٍ الحفظ…");
        api.put("/admin/users/" + id, body, new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                busy(null);
                if (!alive()) {
                    return;
                }
                if (r.ok) {
                    toast(okMessage);
                    markDirty("users");
                    load();
                } else {
                    fail(r);
                }
            }
        });
    }

    private void changeRole() {
        busy("جارٍ تحميل الأدوار…");
        api.get("/admin/roles", null, new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                busy(null);
                if (!alive()) {
                    return;
                }
                if (!r.ok) {
                    fail(r);
                    return;
                }
                final List<JSONObject> roles = new ArrayList<JSONObject>();
                List<String> labels = new ArrayList<String>();
                int current = -1;
                int currentId = Json.num(Json.obj(user, "role"), "id", 0);
                for (JSONObject ro : Json.list(Json.arr(r.obj(), "roles"))) {
                    if (session.isSuper() || Json.num(ro, "level", 0) < session.roleLevel()) {
                        if (Json.num(ro, "id", 0) == currentId) {
                            current = roles.size();
                        }
                        roles.add(ro);
                        labels.add(Json.str(ro, "name", "") + " — مستوى " + Json.num(ro, "level", 0));
                    }
                }
                Dialogs.choose(UserActivity.this, "الدور الجديد", labels, current, new Ui.IntCallback() {
                    @Override
                    public void done(int which) {
                        update(Json.of("role_id", Json.num(roles.get(which), "id", 0)), "تم تغيير الدور");
                    }
                });
            }
        });
    }
}
