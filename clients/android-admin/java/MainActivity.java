package com.upsl.admin;

import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.text.InputType;
import android.view.Gravity;
import android.view.KeyEvent;
import android.view.View;
import android.view.inputmethod.EditorInfo;
import android.widget.EditText;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

import org.json.JSONObject;

/** Sign-in: server address + admin account. Only accounts with the admin.access permission get in. */
public class MainActivity extends Screen {
    private EditText server;
    private EditText login;
    private EditText password;
    private TextView error;
    private TextView submit;
    private CharSequence submitLabel;

    @Override
    protected boolean needsLogin() {
        return false;
    }

    @Override
    protected boolean showBack() {
        return false;
    }

    @Override
    protected void build(Bundle saved) {
        topBar.setVisibility(View.GONE);
        if (session.isLoggedIn() && getIntent().getStringExtra("message") == null) {
            open(DashboardActivity.class);
            finish();
            return;
        }
        LinearLayout col = page();
        col.setGravity(Gravity.CENTER_HORIZONTAL);
        col.setPadding(Ui.dp(this, 22), Ui.dp(this, 36), Ui.dp(this, 22), Ui.dp(this, 32));

        ImageView logo = new ImageView(this);
        logo.setImageResource(R.drawable.upsl_logo);
        col.addView(logo, new LinearLayout.LayoutParams(Ui.dp(this, 84), Ui.dp(this, 84)));
        col.addView(Ui.space(this, 14));
        TextView name = Ui.text(this, "ULTIMATE PS LIBRARY", 20, Ui.INK, true);
        name.setTextAlignment(View.TEXT_ALIGNMENT_CENTER);
        col.addView(name, Ui.fill());
        TextView sub = Ui.text(this, "تطبيق الإدارة — كل ما في لوحة التحكم من هاتفك", 13.5f, Ui.MIST, false);
        sub.setTextAlignment(View.TEXT_ALIGNMENT_CENTER);
        col.addView(sub, Ui.fill());
        col.addView(Ui.space(this, 26));

        LinearLayout card = Ui.card(this);
        card.addView(Ui.h2(this, "تسجيل الدخول"));
        card.addView(label("رابط الموقع"));
        server = Form.input(this, "https://example.com", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI);
        server.setText(session.server());
        card.addView(server);
        card.addView(Ui.space(this, 12));
        card.addView(label("اسم المستخدم أو البريد"));
        login = Form.input(this, "admin", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS);
        Form.ltr(login);
        login.setText(session.prefs().getString("last_login", ""));
        card.addView(login);
        card.addView(Ui.space(this, 12));
        card.addView(label("كلمة المرور"));
        password = Form.input(this, "••••••••", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        password.setImeOptions(EditorInfo.IME_ACTION_GO);
        password.setOnEditorActionListener(new TextView.OnEditorActionListener() {
            @Override
            public boolean onEditorAction(TextView v, int actionId, KeyEvent event) {
                if (actionId == EditorInfo.IME_ACTION_GO) {
                    attempt();
                    return true;
                }
                return false;
            }
        });
        card.addView(password);
        error = Ui.text(this, "", 13, Ui.BAD, false);
        error.setPadding(0, Ui.dp(this, 10), 0, 0);
        error.setVisibility(View.GONE);
        card.addView(error);
        card.addView(Ui.space(this, 16));
        submit = Ui.button(this, "دخول", R.drawable.ic_login, Ui.BTN_PRIMARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                attempt();
            }
        });
        submitLabel = submit.getText();
        card.addView(submit, Ui.fill());
        card.addView(Ui.space(this, 6));
        card.addView(Ui.button(this, "نسيت كلمة المرور؟", 0, Ui.BTN_GHOST, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                forgot();
            }
        }), Ui.fill());
        col.addView(card);

        TextView note = Ui.small(this, "يعمل التطبيق مع خادم ULTIMATE PS LIBRARY عبر واجهة API الإصدار v1. لا تُحفظ كلمة المرور على الجهاز؛ يُحفظ رمز دخول يمكنك إلغاؤه من «رموز API» أو من حسابك.");
        note.setTextAlignment(View.TEXT_ALIGNMENT_CENTER);
        col.addView(note, Ui.fill());
        col.addView(Ui.space(this, 8));
        TextView ver = Ui.small(this, "الإصدار " + BuildInfo.VERSION);
        ver.setTextAlignment(View.TEXT_ALIGNMENT_CENTER);
        col.addView(ver, Ui.fill());

        String message = getIntent().getStringExtra("message");
        if (message != null) {
            showError(message);
        }
    }

    private TextView label(String s) {
        TextView t = Ui.text(this, s, 13, Ui.MIST, true);
        t.setPadding(0, 0, 0, Ui.dp(this, 6));
        return t;
    }

    private void showError(String msg) {
        error.setText(msg);
        error.setVisibility(View.VISIBLE);
    }

    private void attempt() {
        hideKeyboard();
        error.setVisibility(View.GONE);
        final String url = Session.normalizeServer(server.getText().toString());
        final String user = login.getText().toString().trim();
        final String pass = password.getText().toString();
        if (url.length() < 10) {
            showError("اكتب رابط الموقع، مثل https://library.example.com");
            return;
        }
        if (user.length() == 0 || pass.length() == 0) {
            showError("اكتب اسم المستخدم وكلمة المرور");
            return;
        }
        Uri u = Uri.parse(url);
        String host = u.getHost() == null ? "" : u.getHost();
        boolean local = host.equals("localhost") || host.startsWith("127.") || host.startsWith("10.") || host.startsWith("192.168.") || host.endsWith(".local");
        if (url.startsWith("http://") && !session.prefs().getBoolean("http_ok_" + host, false)) {
            Dialogs.confirm(this, "اتصال غير مشفر",
                    "الرابط يبدأ بـ http:// وليس https://، أي أن كلمة المرور ورمز الدخول سيمران دون تشفير."
                            + (local ? " استخدمه فقط على شبكتك المحلية للاختبار." : " لا تستخدمه على الإنترنت؛ فعّل HTTPS على الخادم أولاً."),
                    "متابعة رغم ذلك", true, new Runnable() {
                        @Override
                        public void run() {
                            session.prefs().edit().putBoolean("http_ok_" + Uri.parse(url).getHost(), true).apply();
                            signIn(url, user, pass);
                        }
                    });
            return;
        }
        signIn(url, user, pass);
    }

    private void signIn(final String url, final String user, final String pass) {
        submit.setEnabled(false);
        submit.setText("جارٍ الاتصال…");
        session.setServer(url);
        session.signOut();
        api.get("/status", null, new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                if (!alive()) {
                    return;
                }
                if (!r.ok) {
                    reset();
                    showError(r.status == 404 || r.error.code.equals("bad_response")
                            ? "لم يُعثر على واجهة API على هذا الرابط. تأكد من الرابط (بدون /admin) ومن تفعيل الواجهة في الإعدادات."
                            : r.error.getMessage());
                    return;
                }
                JSONObject st = r.obj();
                if (Json.bool(st, "maintenance")) {
                    Ui.toast(MainActivity.this, "الموقع في وضع الصيانة حالياً");
                }
                String device = "تطبيق الإدارة — " + Build.MANUFACTURER + " " + Build.MODEL;
                api.post("/auth/login", Json.of("login", user, "password", pass, "device_name", device.length() > 60 ? device.substring(0, 60) : device),
                        new Api.Callback() {
                            @Override
                            public void done(Api.Result r2) {
                                if (!alive()) {
                                    return;
                                }
                                if (!r2.ok) {
                                    reset();
                                    showError(r2.error.describe());
                                    return;
                                }
                                JSONObject d = r2.obj();
                                JSONObject u = Json.obj(d, "user");
                                String token = Json.str(d, "token");
                                session.signIn(token, u);
                                if (!session.can("admin.access")) {
                                    api.post("/auth/logout", new JSONObject(), null);
                                    session.signOut();
                                    reset();
                                    showError("هذا الحساب لا يملك صلاحية الدخول إلى لوحة الإدارة.");
                                    return;
                                }
                                session.prefs().edit().putString("last_login", user).apply();
                                password.setText("");
                                Intent i = new Intent(MainActivity.this, DashboardActivity.class);
                                i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
                                startActivity(i);
                                finish();
                            }
                        });
            }
        });
    }

    private void reset() {
        submit.setEnabled(true);
        submit.setText(submitLabel);
    }

    private void forgot() {
        final String url = Session.normalizeServer(server.getText().toString());
        if (url.length() < 10) {
            showError("اكتب رابط الموقع أولاً");
            return;
        }
        Dialogs.prompt(this, "استعادة كلمة المرور", "البريد الإلكتروني للحساب", "", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS,
                new Ui.Callback<String>() {
                    @Override
                    public void done(String email) {
                        if (email.length() == 0) {
                            return;
                        }
                        session.setServer(url);
                        api.post("/auth/forgot-password", Json.of("email", email), new Api.Callback() {
                            @Override
                            public void done(Api.Result r) {
                                if (!alive()) {
                                    return;
                                }
                                Dialogs.info(MainActivity.this, "استعادة كلمة المرور", r.ok
                                        ? "إن كان البريد مسجلاً فستصله رسالة فيها رابط تعيين كلمة مرور جديدة. افتح الرابط من المتصفح ثم عد لتسجيل الدخول."
                                        : r.error.describe());
                            }
                        });
                    }
                });
    }
}
