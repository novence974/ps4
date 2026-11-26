package com.upsl.admin;

import android.app.AlertDialog;
import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;
import android.widget.TextView;

import org.json.JSONObject;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.List;
import java.util.Map;

/** Accounts: search, role and status filters, create account. */
public class UsersActivity extends ListScreen {
    private final int[] role = {0};
    private final int[] status = {0};
    private static final String[] ROLE_KEYS = {"", "super_admin", "admin", "moderator", "user"};
    private static final String[] STATUS_KEYS = {"", "active", "suspended"};
    private TextView totalView;

    @Override
    protected String endpoint() {
        return "/admin/users";
    }

    @Override
    protected String searchHint() {
        return "ابحث بالاسم أو البريد";
    }

    @Override
    protected void query(Map<String, String> q) {
        if (role[0] > 0) {
            q.put("role", ROLE_KEYS[role[0]]);
        }
        if (status[0] > 0) {
            q.put("status", STATUS_KEYS[status[0]]);
        }
    }

    @Override
    protected void build(Bundle saved) {
        setTitleText("المستخدمون");
        if (!require("users.view", "users.manage")) {
            return;
        }
        if (session.can("users.manage")) {
            addAction(R.drawable.ic_user_plus, "إنشاء حساب", new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    create();
                }
            });
        }
        super.build(saved);
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (list != null && consumeDirty("users")) {
            reload();
        }
    }

    @Override
    protected void buildHeader(LinearLayout header) {
        header.addView(filters(Arrays.asList("كل الأدوار", "مدير رئيسي", "مدير", "مشرف", "مستخدم"), role, new Runnable() {
            @Override
            public void run() {
                reload();
            }
        }));
        header.addView(filters(Arrays.asList("كل الحالات", "نشط", "موقوف"), status, new Runnable() {
            @Override
            public void run() {
                reload();
            }
        }));
        totalView = Ui.small(this, "");
        totalView.setPadding(0, 0, 0, Ui.dp(this, 8));
        header.addView(totalView);
    }

    @Override
    protected void onLoaded(Api.Result r) {
        totalView.setText(Fmt.count(r.metaInt("total", items.size()), "حساب واحد", "حسابان", "حسابات"));
    }

    @Override
    protected View row(JSONObject u, int position) {
        String avatar = ImageLoader.pick(Json.obj(u, "avatar"), "sm", "sm_jpg");
        View lead = avatar != null ? Ui.remote(this, avatar, 42, 42, 21) : Ui.iconPlate(this, R.drawable.ic_user, Ui.ACCENT2, 42);
        JSONObject r = Json.obj(u, "role");
        String sub = "@" + Json.str(u, "username", "") + " • " + Json.str(u, "email", "") + "\n"
                + (Json.str(u, "last_login_at") != null ? "آخر دخول " + Fmt.ago(Json.str(u, "last_login_at")) : "لم يسجل الدخول بعد")
                + " • " + Fmt.num(Json.lng(u, "downloads", 0)) + " تنزيل";
        LinearLayout chips = Ui.vbox(this);
        chips.setGravity(android.view.Gravity.END);
        chips.addView(Ui.chip(this, Json.str(r, "name", "?"), "super_admin".equals(Json.str(r, "slug")) ? Ui.ACCENT : Ui.ACCENT2));
        if ("suspended".equals(Json.str(u, "status"))) {
            chips.addView(Ui.space(this, 4));
            chips.addView(Ui.chip(this, "موقوف", Ui.BAD));
        }
        if (Json.bool(u, "locked")) {
            chips.addView(Ui.space(this, 4));
            chips.addView(Ui.chip(this, "مقفل مؤقتاً", Ui.WARN));
        }
        return Ui.row(this, lead, Json.str(u, "display_name", Json.str(u, "username", "")), sub, chips);
    }

    @Override
    protected void onItem(JSONObject u) {
        open(UserActivity.class, "id", Json.num(u, "id", 0));
    }

    private void create() {
        if (session.can("roles.assign")) {
            busy("جارٍ تحميل الأدوار…");
            api.get("/admin/roles", null, new Api.Callback() {
                @Override
                public void done(Api.Result r) {
                    busy(null);
                    if (!alive()) {
                        return;
                    }
                    List<JSONObject> roles = new ArrayList<JSONObject>();
                    for (JSONObject ro : Json.list(Json.arr(r.obj(), "roles"))) {
                        if (session.isSuper() || Json.num(ro, "level", 0) < session.roleLevel()) {
                            roles.add(ro);
                        }
                    }
                    createDialog(roles);
                }
            });
        } else {
            createDialog(new ArrayList<JSONObject>());
        }
    }

    private void createDialog(List<JSONObject> roles) {
        final Form f = new Form(this);
        f.text("username", "اسم المستخدم", null, "player_one", Form.CODE).required().max(32)
                .pattern("^[A-Za-z0-9_.\\-]{3,32}$", "من 3 إلى 32: حروف إنجليزية وأرقام و _ . -");
        f.text("email", "البريد الإلكتروني", null, "name@example.com", Form.EMAIL).required().max(190);
        f.text("password", "كلمة المرور", null, "8 أحرف على الأقل", Form.PASSWORD).required();
        f.text("password_confirmation", "تأكيد كلمة المرور", null, "", Form.PASSWORD).required();
        if (!roles.isEmpty()) {
            f.select("role_id", "الدور", Form.opts(roles, "id", "name"), "4", false).required();
        } else {
            f.note("سيُنشأ الحساب بدور «مستخدم». تعيين أدوار أعلى يتطلب صلاحية «تعيين الأدوار».");
        }
        final AlertDialog[] d = new AlertDialog[1];
        d[0] = Dialogs.custom(this, "إنشاء حساب", f.view(), "إنشاء", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                if (!f.validate()) {
                    return;
                }
                JSONObject body = f.values();
                if (!Json.str(body, "password", "").equals(Json.str(body, "password_confirmation", ""))) {
                    f.field("password_confirmation").setError("كلمتا المرور غير متطابقتين");
                    return;
                }
                if (body.has("role_id")) {
                    Json.put(body, "role_id", Integer.parseInt(Json.str(body, "role_id", "4")));
                }
                api.post("/admin/users", body, new Api.Callback() {
                    @Override
                    public void done(Api.Result r) {
                        if (!alive()) {
                            return;
                        }
                        if (r.ok) {
                            d[0].dismiss();
                            toast("أُنشئ الحساب");
                            reload();
                        } else {
                            String rest = r.error.fields == null ? r.error.getMessage() : f.showErrors(r.error.fields);
                            if (rest.length() > 0) {
                                toast(rest);
                            }
                        }
                    }
                });
            }
        });
    }

    @Override
    protected String emptyTitle() {
        return "لا توجد حسابات مطابقة";
    }

    @Override
    protected int emptyIcon() {
        return R.drawable.ic_users;
    }
}
