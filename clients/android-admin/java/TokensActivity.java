package com.upsl.admin;

import android.os.Bundle;
import android.view.View;

import org.json.JSONObject;

/** Active API sign-in tokens of every account (apps, consoles, this admin app); revoke any of them. */
public class TokensActivity extends ListScreen {
    @Override
    protected String endpoint() {
        return "/admin/tokens";
    }

    @Override
    protected void build(Bundle saved) {
        setTitleText("رموز API");
        if (!require("api.manage")) {
            return;
        }
        super.build(saved);
    }

    @Override
    protected View row(JSONObject t, int position) {
        boolean current = Json.bool(t, "is_current");
        JSONObject u = Json.obj(t, "user");
        String sub = "@" + Json.str(u, "username", "") + " • أُنشئ " + Fmt.dateOnly(Json.str(t, "created_at"))
                + "\n" + (Json.str(t, "last_used_at") != null ? "آخر استخدام " + Fmt.ago(Json.str(t, "last_used_at")) : "لم يُستخدم")
                + (Json.str(t, "last_used_ip") != null ? " • " + Fmt.ltr(Json.str(t, "last_used_ip")) : "")
                + (Json.str(t, "expires_at") != null ? "\nينتهي " + Fmt.dateOnly(Json.str(t, "expires_at")) : "");
        return Ui.row(this, Ui.iconPlate(this, R.drawable.ic_key, current ? Ui.OK : Ui.ACCENT2, 40), Json.str(t, "name", "رمز"), sub,
                current ? Ui.chip(this, "هذا الجهاز", Ui.OK) : null);
    }

    @Override
    protected void onItem(final JSONObject t) {
        final boolean current = Json.bool(t, "is_current");
        Dialogs.confirm(this, "إلغاء الرمز", current
                        ? "هذا رمز الدخول الذي يستخدمه هذا التطبيق الآن؛ إلغاؤه يسجّل خروجك."
                        : "سيُسجَّل خروج «" + Json.str(t, "name", "") + "» الخاص بـ @" + Json.str(Json.obj(t, "user"), "username", "") + " فوراً.",
                "إلغاء الرمز", true, new Runnable() {
                    @Override
                    public void run() {
                        api.delete("/admin/tokens/" + Json.num(t, "id", 0), new Api.Callback() {
                            @Override
                            public void done(Api.Result r) {
                                if (!alive()) {
                                    return;
                                }
                                if (!r.ok) {
                                    fail(r);
                                } else if (current) {
                                    toLogin("أُلغي رمز الدخول لهذا الجهاز");
                                } else {
                                    toast("أُلغي الرمز");
                                    reload();
                                }
                            }
                        });
                    }
                });
    }

    @Override
    protected String emptyTitle() {
        return "لا توجد رموز نشطة";
    }

    @Override
    protected int emptyIcon() {
        return R.drawable.ic_key;
    }
}
