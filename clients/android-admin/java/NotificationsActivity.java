package com.upsl.admin;

import android.app.AlertDialog;
import android.os.Bundle;
import android.view.View;

import org.json.JSONObject;

/** Notifications sent by admins (to everyone or one account); compose and delete. */
public class NotificationsActivity extends ListScreen {
    @Override
    protected String endpoint() {
        return "/admin/notifications";
    }

    @Override
    protected void build(Bundle saved) {
        setTitleText("الإشعارات");
        if (!require("notifications.send")) {
            return;
        }
        addAction(R.drawable.ic_send, "إشعار جديد", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                compose(NotificationsActivity.this, null, new Runnable() {
                    @Override
                    public void run() {
                        reload();
                    }
                });
            }
        });
        super.build(saved);
    }

    @Override
    protected View row(JSONObject n, int position) {
        boolean broadcast = Json.bool(n, "broadcast");
        String target = broadcast ? "للجميع" : "إلى: " + Json.str(n, "target", "مستخدم");
        String sub = (Json.str(n, "body") != null ? Json.str(n, "body") + "\n" : "") + target + " • " + Fmt.ago(Json.str(n, "created_at"))
                + (Json.str(n, "sender") != null ? " • من " + Json.str(n, "sender") : "");
        return Ui.row(this, Ui.iconPlate(this, broadcast ? R.drawable.ic_megaphone : R.drawable.ic_bell, broadcast ? Ui.ACCENT : Ui.ACCENT3, 40),
                Json.str(n, "title", ""), sub, null);
    }

    @Override
    protected void onItem(final JSONObject n) {
        StringBuilder sb = new StringBuilder();
        if (Json.str(n, "body") != null) {
            sb.append(Json.str(n, "body")).append("\n\n");
        }
        if (Json.str(n, "link") != null) {
            sb.append("الرابط: ").append(Json.str(n, "link")).append("\n");
        }
        sb.append(Fmt.date(Json.str(n, "created_at")));
        AlertDialog d = Dialogs.builder(this).setTitle(Json.str(n, "title", ""))
                .setMessage(sb.toString())
                .setPositiveButton("حسناً", null)
                .setNegativeButton("حذف", new android.content.DialogInterface.OnClickListener() {
                    @Override
                    public void onClick(android.content.DialogInterface dialog, int which) {
                        Dialogs.confirm(NotificationsActivity.this, "حذف الإشعار", "سيختفي الإشعار من صناديق المستخدمين.", "حذف", true, new Runnable() {
                            @Override
                            public void run() {
                                api.delete("/admin/notifications/" + Json.num(n, "id", 0), new Api.Callback() {
                                    @Override
                                    public void done(Api.Result r) {
                                        if (!alive()) {
                                            return;
                                        }
                                        if (r.ok) {
                                            toast("حُذف الإشعار");
                                            reload();
                                        } else {
                                            fail(r);
                                        }
                                    }
                                });
                            }
                        });
                    }
                }).create();
        d.show();
        if (d.getWindow() != null) {
            d.getWindow().getDecorView().setLayoutDirection(View.LAYOUT_DIRECTION_RTL);
        }
    }

    /** Compose dialog; user = id/username/e-mail to pre-fill a single recipient. */
    public static void compose(final Screen s, String user, final Runnable after) {
        final Form f = new Form(s);
        f.select("audience", "المستلمون", Form.opts("all", "كل المستخدمين", "user", "مستخدم واحد"), user == null ? "all" : "user", false).required();
        final Form.Field who = f.text("user", "المستخدم", user, "رقم الحساب أو اسم المستخدم أو البريد", Form.TEXT).max(190);
        who.visible(user != null);
        f.onChange(new Runnable() {
            @Override
            public void run() {
                who.visible("user".equals(f.str("audience")));
            }
        });
        f.text("title", "العنوان", null).required().max(190);
        f.text("body", "النص", null, "", Form.MULTILINE).max(1000);
        f.text("link", "رابط (اختياري)", null, "/game/… أو https://…", Form.URL).max(500)
                .help("رابط داخلي يبدأ بـ / أو رابط كامل يبدأ بـ https://");
        final AlertDialog[] d = new AlertDialog[1];
        d[0] = Dialogs.custom(s, "إشعار جديد", f.view(), "إرسال", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                if ("user".equals(f.str("audience")) && (f.str("user") == null || f.str("user").length() == 0)) {
                    who.setError("حدد المستخدم");
                    return;
                }
                if (!f.validate()) {
                    return;
                }
                s.api.post("/admin/notifications", f.values(), new Api.Callback() {
                    @Override
                    public void done(Api.Result r) {
                        if (!s.alive()) {
                            return;
                        }
                        if (r.ok) {
                            d[0].dismiss();
                            s.toast("أُرسل الإشعار");
                            if (after != null) {
                                after.run();
                            }
                        } else {
                            String rest = r.error.fields == null ? r.error.getMessage() : f.showErrors(r.error.fields);
                            if (rest.length() > 0) {
                                s.toast(rest);
                            }
                        }
                    }
                });
            }
        });
    }

    @Override
    protected String emptyTitle() {
        return "لم تُرسل إشعارات بعد";
    }

    @Override
    protected int emptyIcon() {
        return R.drawable.ic_bell;
    }
}
