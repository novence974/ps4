package com.upsl.admin;

import android.app.Activity;
import android.app.Dialog;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.view.inputmethod.InputMethodManager;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import org.json.JSONObject;

/**
 * Base for every screen: RTL glass theme, top bar, loading / error / permission states,
 * session checks (an expired token sends the admin back to the login screen) and file picking.
 */
public abstract class Screen extends Activity {
    protected Api api;
    protected Session session;
    protected LinearLayout topBar;
    protected TextView titleView;
    protected LinearLayout actions;
    protected FrameLayout content;
    private Dialog busyDialog;
    private Ui.Callback<Uri> pickCallback;
    private static final int REQ_PICK = 7301;
    private boolean destroyed;

    /** Screens that work without a session (login) return false. */
    protected boolean needsLogin() {
        return true;
    }

    /** Back arrow in the top bar (false for the dashboard). */
    protected boolean showBack() {
        return true;
    }

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        Ui.rtl(this);
        api = Api.get(this);
        session = Session.get(this);
        if (needsLogin() && !session.isLoggedIn()) {
            toLogin(null);
            return;
        }
        LinearLayout root = Ui.vbox(this);
        topBar = Ui.hbox(this);
        topBar.setPadding(Ui.dp(this, 6), Ui.dp(this, 6), Ui.dp(this, 6), Ui.dp(this, 6));
        topBar.setMinimumHeight(Ui.dp(this, 58));
        if (showBack()) {
            topBar.addView(Ui.iconButton(this, R.drawable.ic_chev_right, "رجوع", new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    onBackPressed();
                }
            }));
        } else {
            topBar.addView(Ui.hspace(this, 10));
        }
        titleView = Ui.text(this, "", 19, Ui.INK, true);
        Ui.single(titleView);
        titleView.setPadding(Ui.dp(this, 4), 0, Ui.dp(this, 4), 0);
        topBar.addView(titleView, Ui.weight(1));
        actions = Ui.hbox(this);
        topBar.addView(actions);
        root.addView(topBar, Ui.fill());
        content = new FrameLayout(this);
        root.addView(content, new LinearLayout.LayoutParams(Ui.MATCH, 0, 1f));
        setContentView(root);
        build(savedInstanceState);
    }

    /** Builds the screen (called once the session check passed). */
    protected abstract void build(Bundle saved);

    @Override
    protected void onResume() {
        super.onResume();
        api.setAuthListener(new Api.AuthListener() {
            @Override
            public void onUnauthorized() {
                toLogin("انتهت جلستك أو أُلغي رمز الدخول، سجّل الدخول من جديد");
            }
        });
    }

    @Override
    protected void onDestroy() {
        destroyed = true;
        busy(null);
        super.onDestroy();
    }

    /** False once the screen is closing: async callbacks must not touch views then. */
    public boolean alive() {
        return !destroyed && !isFinishing();
    }

    public void toLogin(String message) {
        session.signOut();
        Intent i = new Intent(this, MainActivity.class);
        i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
        if (message != null) {
            i.putExtra("message", message);
        }
        startActivity(i);
        finish();
    }

    // ------------------------------------------------------------------ top bar

    public void setTitleText(String t) {
        titleView.setText(t);
        setTitle(t);
    }

    public ImageView addAction(int icon, String description, View.OnClickListener click) {
        ImageView b = Ui.iconButton(this, icon, description, click);
        actions.addView(b);
        return b;
    }

    // ------------------------------------------------------------------ content states

    public void setBody(View v) {
        content.removeAllViews();
        content.addView(v, new FrameLayout.LayoutParams(Ui.MATCH, Ui.MATCH));
    }

    /** Scrollable page with standard padding; returns the column to add views to. */
    public LinearLayout page() {
        ScrollView sv = new ScrollView(this);
        sv.setFillViewport(true);
        sv.setClipToPadding(false);
        LinearLayout col = Ui.vbox(this);
        col.setPadding(Ui.dp(this, 16), Ui.dp(this, 4), Ui.dp(this, 16), Ui.dp(this, 32));
        sv.addView(col, new FrameLayout.LayoutParams(Ui.MATCH, Ui.WRAP));
        setBody(sv);
        return col;
    }

    public void showLoading() {
        FrameLayout f = new FrameLayout(this);
        f.addView(Ui.spinner(this), new FrameLayout.LayoutParams(Ui.dp(this, 40), Ui.dp(this, 40), Gravity.CENTER));
        setBody(f);
    }

    public void showError(Api.Error e, final Runnable retry) {
        int icon = e != null && e.status == 0 ? R.drawable.ic_wifi_off : R.drawable.ic_alert;
        String title = e != null && e.status == 403 ? "ليست لديك صلاحية" : e != null && e.status == 0 ? "لا يوجد اتصال بالخادم" : "تعذر التحميل";
        setBody(Ui.state(this, icon, e != null && e.status == 403 ? Ui.WARN : Ui.BAD, title,
                e == null ? "" : e.getMessage(), retry == null ? null : "إعادة المحاولة", retry == null ? null : new View.OnClickListener() {
                    @Override
                    public void onClick(View v) {
                        retry.run();
                    }
                }));
    }

    /** Shows a "no permission" state when the admin lacks every listed permission. */
    public boolean require(String... perms) {
        if (session.can(perms)) {
            return true;
        }
        setBody(Ui.state(this, R.drawable.ic_lock, Ui.WARN, "ليست لديك صلاحية لهذا القسم",
                "اطلب من المدير الرئيسي منح دورك الصلاحية المناسبة من «الأدوار والصلاحيات».", null, null));
        return false;
    }

    // ------------------------------------------------------------------ feedback

    public void toast(String msg) {
        Ui.toast(this, msg);
    }

    /** Blocking progress while a short request runs (null hides it). */
    public void busy(String message) {
        if (busyDialog != null) {
            try {
                busyDialog.dismiss();
            } catch (RuntimeException ignored) {
            }
            busyDialog = null;
        }
        if (message != null && alive()) {
            busyDialog = Dialogs.progress(this, message);
        }
    }

    /** Standard handling of a failed request: toast the server's message (field errors go to forms). */
    public void fail(Api.Result r) {
        if (r.error != null) {
            toast(r.error.describe());
        }
    }

    public void hideKeyboard() {
        View v = getCurrentFocus();
        if (v != null) {
            InputMethodManager imm = (InputMethodManager) getSystemService(INPUT_METHOD_SERVICE);
            if (imm != null) {
                imm.hideSoftInputFromWindow(v.getWindowToken(), 0);
            }
        }
    }

    // ------------------------------------------------------------------ navigation & pickers

    public void open(Class<?> screen, Object... extras) {
        Intent i = new Intent(this, screen);
        for (int k = 0; k + 1 < extras.length; k += 2) {
            Object v = extras[k + 1];
            String key = String.valueOf(extras[k]);
            if (v instanceof Integer) {
                i.putExtra(key, (Integer) v);
            } else if (v instanceof Long) {
                i.putExtra(key, (Long) v);
            } else if (v instanceof Boolean) {
                i.putExtra(key, (Boolean) v);
            } else if (v != null) {
                i.putExtra(key, String.valueOf(v));
            }
        }
        startActivity(i);
    }

    public void openUrl(String url) {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
        } catch (RuntimeException e) {
            toast("لا يوجد تطبيق لفتح الرابط");
        }
    }

    /** Opens the system file picker (Storage Access Framework); persistable access allows resuming uploads later. */
    public void pickFile(String[] mimeTypes, Ui.Callback<Uri> cb) {
        pickCallback = cb;
        Intent i = new Intent(Intent.ACTION_OPEN_DOCUMENT);
        i.addCategory(Intent.CATEGORY_OPENABLE);
        i.setType(mimeTypes.length == 1 ? mimeTypes[0] : "*/*");
        if (mimeTypes.length > 1) {
            i.putExtra(Intent.EXTRA_MIME_TYPES, mimeTypes);
        }
        i.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_GRANT_PERSISTABLE_URI_PERMISSION);
        try {
            startActivityForResult(i, REQ_PICK);
        } catch (RuntimeException e) {
            toast("لا يوجد مدير ملفات على الجهاز");
        }
    }

    public void pickImage(Ui.Callback<Uri> cb) {
        pickFile(new String[]{"image/jpeg", "image/png", "image/webp", "image/gif"}, cb);
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == REQ_PICK && resultCode == RESULT_OK && data != null && data.getData() != null && pickCallback != null) {
            Uri uri = data.getData();
            try {
                getContentResolver().takePersistableUriPermission(uri, Intent.FLAG_GRANT_READ_URI_PERMISSION);
            } catch (RuntimeException ignored) {
                // some providers do not offer persistable grants; the upload still works while the app is open
            }
            Ui.Callback<Uri> cb = pickCallback;
            pickCallback = null;
            cb.done(uri);
        }
    }

    /** Android 13+: asks once for the notification permission used by the upload progress notification. */
    public void askNotifications() {
        if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission("android.permission.POST_NOTIFICATIONS") != android.content.pm.PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{"android.permission.POST_NOTIFICATIONS"}, 7302);
        }
    }

    // ------------------------------------------------------------------ small shared helpers

    private static final java.util.Set<String> DIRTY = new java.util.HashSet<String>();

    /** Marks lists that must reload when shown again ("games", "users"…). */
    public static void markDirty(String... keys) {
        synchronized (DIRTY) {
            java.util.Collections.addAll(DIRTY, keys);
        }
    }

    public static boolean consumeDirty(String key) {
        synchronized (DIRTY) {
            return DIRTY.remove(key);
        }
    }

    public static JSONObject extraJson(Intent i, String key) {
        return Json.parse(i.getStringExtra(key));
    }

    public int intExtra(String key) {
        return getIntent().getIntExtra(key, 0);
    }
}
