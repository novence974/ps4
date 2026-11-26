package com.upsl.admin;

import android.app.AlertDialog;
import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;
import android.widget.TextView;

import org.json.JSONObject;

import java.io.InputStream;
import java.util.ArrayList;
import java.util.List;

/** The signed-in admin: profile, avatar, password, devices, sign-out and app information. */
public class AccountActivity extends Screen {
    private JSONObject profile;

    @Override
    protected void build(Bundle saved) {
        setTitleText("حسابي");
        load();
    }

    private void load() {
        showLoading();
        api.get("/user/profile", null, new Api.Callback() {
            @Override
            public void done(final Api.Result r) {
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
                profile = r.obj();
                session.updateUser(mergePermissions(profile));
                api.get("/user/tokens", null, new Api.Callback() {
                    @Override
                    public void done(Api.Result t) {
                        if (alive()) {
                            render(t.ok ? Json.list(t.arr()) : new ArrayList<JSONObject>());
                        }
                    }
                });
            }
        });
    }

    /** The profile endpoint also returns the permissions of the current account; keep the old ones if missing. */
    private JSONObject mergePermissions(JSONObject p) {
        if (!p.has("permissions") && session.user() != null && session.user().has("permissions")) {
            Json.put(p, "permissions", session.user().optJSONArray("permissions"));
        }
        return p;
    }

    private void render(List<JSONObject> tokens) {
        LinearLayout col = page();
        LinearLayout head = Ui.card(this);
        LinearLayout top = Ui.hbox(this);
        String avatar = ImageLoader.pick(Json.obj(profile, "avatar"), "sm", "sm_jpg");
        top.addView(avatar != null ? Ui.remote(this, avatar, 68, 68, 34) : Ui.iconPlate(this, R.drawable.ic_user, Ui.ACCENT2, 68));
        top.addView(Ui.hspace(this, 14));
        LinearLayout who = Ui.vbox(this);
        who.addView(Ui.text(this, Json.str(profile, "display_name", ""), 18, Ui.INK, true));
        TextView handle = Ui.muted(this, "@" + Json.str(profile, "username", "") + " • " + Json.str(profile, "email", ""));
        who.addView(handle);
        who.addView(Ui.space(this, 6));
        who.addView(Ui.chip(this, session.roleName(), Ui.ACCENT));
        top.addView(who, Ui.weight(1));
        head.addView(top);
        head.addView(Ui.space(this, 12));
        List<View> b = new ArrayList<View>();
        b.add(Ui.button(this, "تعديل البيانات", R.drawable.ic_edit, Ui.BTN_SECONDARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                editProfile();
            }
        }));
        b.add(Ui.button(this, "تغيير الصورة", R.drawable.ic_image, Ui.BTN_SECONDARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                pickImage(new Ui.Callback<Uri>() {
                    @Override
                    public void done(Uri uri) {
                        busy("جارٍ رفع الصورة…");
                        api.upload("/user/avatar", uri, "file", null, null, new Api.Callback() {
                            @Override
                            public void done(Api.Result r) {
                                busy(null);
                                if (!alive()) {
                                    return;
                                }
                                if (r.ok) {
                                    toast("تم تحديث الصورة");
                                    load();
                                } else {
                                    fail(r);
                                }
                            }
                        });
                    }
                });
            }
        }));
        b.add(Ui.button(this, "كلمة المرور", R.drawable.ic_lock, Ui.BTN_SECONDARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                changePassword();
            }
        }));
        b.add(Ui.button(this, "تسجيل الخروج", R.drawable.ic_logout, Ui.BTN_DANGER, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                Dialogs.confirm(AccountActivity.this, "تسجيل الخروج", "سيُلغى رمز الدخول لهذا الجهاز. عمليات الرفع الجارية ستتوقف.", "خروج", true, new Runnable() {
                    @Override
                    public void run() {
                        api.post("/auth/logout", new JSONObject(), null);
                        toLogin(null);
                    }
                });
            }
        }));
        head.addView(Ui.grid(this, b, 2));
        col.addView(head);

        LinearLayout dev = Ui.card(this);
        dev.addView(Ui.h2(this, "أجهزتي (" + tokens.size() + ")"));
        for (final JSONObject t : tokens) {
            final boolean current = Json.bool(t, "current");
            LinearLayout r = Ui.row(this, Ui.iconPlate(this, current ? R.drawable.ic_phone : R.drawable.ic_monitor, current ? Ui.OK : Ui.ACCENT2, 36),
                    Json.str(t, "name", "جهاز"), (Json.str(t, "last_used_at") != null ? "آخر استخدام " + Fmt.ago(Json.str(t, "last_used_at")) : "")
                            + (Json.str(t, "last_used_ip") != null ? " • " + Fmt.ltr(Json.str(t, "last_used_ip")) : ""),
                    current ? Ui.chip(this, "هذا الجهاز", Ui.OK) : null);
            if (!current) {
                r.setOnClickListener(new View.OnClickListener() {
                    @Override
                    public void onClick(View v) {
                        Dialogs.confirm(AccountActivity.this, "إلغاء الجهاز", "سيُسجَّل خروج «" + Json.str(t, "name", "") + "».", "إلغاء", true, new Runnable() {
                            @Override
                            public void run() {
                                api.delete("/user/tokens/" + Json.num(t, "id", 0), new Api.Callback() {
                                    @Override
                                    public void done(Api.Result r2) {
                                        if (!alive()) {
                                            return;
                                        }
                                        if (r2.ok) {
                                            toast("أُلغي الجهاز");
                                            load();
                                        } else {
                                            fail(r2);
                                        }
                                    }
                                });
                            }
                        });
                    }
                });
            }
            dev.addView(r);
        }
        col.addView(dev);

        LinearLayout about = Ui.card(this);
        about.addView(Ui.h2(this, "عن التطبيق"));
        about.addView(Ui.kv(this, "الإصدار", BuildInfo.VERSION + " (" + BuildInfo.VERSION_CODE + ")"));
        TextView server = Ui.mono(this, session.server(), 12, Ui.MIST);
        about.addView(Ui.kv(this, "الخادم", ""));
        about.addView(server);
        about.addView(Ui.space(this, 8));
        about.addView(Ui.button(this, "تراخيص المكونات", R.drawable.ic_info, Ui.BTN_GHOST, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                Dialogs.info(AccountActivity.this, "تراخيص المكونات", readAsset("third_party_licenses.txt"));
            }
        }), Ui.fill());
        col.addView(about);
    }

    private String readAsset(String name) {
        try {
            InputStream in = getAssets().open(name);
            return Api.readAll(in);
        } catch (java.io.IOException e) {
            return "Readex Pro — SIL Open Font License 1.1\nLucide icons — ISC License";
        }
    }

    private void editProfile() {
        final Form f = new Form(this);
        f.text("display_name", "الاسم الظاهر", Json.str(profile, "display_name")).max(64);
        f.text("email", "البريد الإلكتروني", Json.str(profile, "email"), "", Form.EMAIL).required().max(190)
                .help("تغيير البريد قد يتطلب تأكيده من جديد.");
        f.text("bio", "نبذة", Json.str(profile, "bio"), "", Form.MULTILINE).max(500);
        final AlertDialog[] d = new AlertDialog[1];
        d[0] = Dialogs.custom(this, "تعديل البيانات", f.view(), "حفظ", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                if (!f.validate()) {
                    return;
                }
                api.put("/user/profile", f.values(), new Api.Callback() {
                    @Override
                    public void done(Api.Result r) {
                        if (!alive()) {
                            return;
                        }
                        if (r.ok) {
                            d[0].dismiss();
                            toast("تم الحفظ");
                            load();
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

    private void changePassword() {
        final Form f = new Form(this);
        f.text("current_password", "كلمة المرور الحالية", null, "", Form.PASSWORD).required();
        f.text("password", "كلمة المرور الجديدة", null, "8 أحرف على الأقل", Form.PASSWORD).required();
        f.text("password_confirmation", "تأكيد كلمة المرور", null, "", Form.PASSWORD).required();
        f.note("تغيير كلمة المرور يلغي جلسات الأجهزة الأخرى؛ هذا الجهاز يبقى مسجلاً.");
        final AlertDialog[] d = new AlertDialog[1];
        d[0] = Dialogs.custom(this, "تغيير كلمة المرور", f.view(), "تغيير", new View.OnClickListener() {
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
                api.put("/user/password", body, new Api.Callback() {
                    @Override
                    public void done(Api.Result r) {
                        if (!alive()) {
                            return;
                        }
                        if (r.ok) {
                            d[0].dismiss();
                            toast(Json.str(r.obj(), "message", "تم تغيير كلمة المرور"));
                            load();
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
}
