package com.upsl.admin;

import android.app.Activity;
import android.app.Application;
import android.app.Dialog;
import android.content.ContentProvider;
import android.content.ContentValues;
import android.content.Intent;
import android.database.Cursor;
import android.database.MatrixCursor;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.net.Uri;
import android.os.Looper;
import android.os.ParcelFileDescriptor;
import android.provider.OpenableColumns;
import android.view.View;
import android.view.ViewGroup;
import android.widget.EditText;
import android.widget.ListView;
import android.widget.ScrollView;
import android.widget.TextView;

import org.json.JSONObject;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.RuntimeEnvironment;
import org.robolectric.android.controller.ActivityController;
import org.robolectric.annotation.Config;
import org.robolectric.annotation.GraphicsMode;
import org.robolectric.annotation.LooperMode;
import org.robolectric.shadows.ShadowDialog;

import java.io.File;
import java.io.FileOutputStream;
import java.util.ArrayList;
import java.util.List;
import java.util.Random;

import static org.junit.Assert.assertTrue;
import static org.robolectric.Shadows.shadowOf;

/**
 * Opens every screen of the admin app inside Robolectric (the real Android 14 framework on the JVM) against the
 * live server, drives the main interactions, records crashes and saves a screenshot of each state.
 */
@RunWith(RobolectricTestRunner.class)
@Config(sdk = 34, qualifiers = "w392dp-h860dp-xhdpi")
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@LooperMode(LooperMode.Mode.PAUSED)
public class ScreensTest {
    private static final String SERVER = System.getProperty("upsl.server", "http://127.0.0.1:8080");
    private static final File OUT = new File(System.getProperty("upsl.shots", "shots"));
    private final List<String> problems = new ArrayList<String>();
    private final List<String> passed = new ArrayList<String>();
    private Application app;

    /** Serves a test file like a document picked from the system file manager (content://, with name and size). */
    public static class DocProvider extends ContentProvider {
        static File file;

        @Override
        public boolean onCreate() {
            return true;
        }

        @Override
        public Cursor query(Uri uri, String[] projection, String selection, String[] args, String sort) {
            MatrixCursor c = new MatrixCursor(projection);
            Object[] row = new Object[projection.length];
            for (int i = 0; i < projection.length; i++) {
                row[i] = OpenableColumns.SIZE.equals(projection[i]) ? (Object) file.length() : file.getName();
            }
            c.addRow(row);
            return c;
        }

        @Override
        public ParcelFileDescriptor openFile(Uri uri, String mode) {
            try {
                return ParcelFileDescriptor.open(file, ParcelFileDescriptor.MODE_READ_ONLY);
            } catch (java.io.FileNotFoundException e) {
                throw new RuntimeException(e);
            }
        }

        @Override
        public String getType(Uri uri) {
            return "application/octet-stream";
        }

        @Override
        public Uri insert(Uri uri, ContentValues values) {
            return null;
        }

        @Override
        public int delete(Uri uri, String s, String[] a) {
            return 0;
        }

        @Override
        public int update(Uri uri, ContentValues v, String s, String[] a) {
            return 0;
        }
    }

    // ------------------------------------------------------------------ helpers

    private void settle(long ms) {
        long end = System.currentTimeMillis() + ms;
        while (System.currentTimeMillis() < end) {
            shadowOf(Looper.getMainLooper()).idle();
            try {
                Thread.sleep(40);
            } catch (InterruptedException e) {
                return;
            }
        }
        shadowOf(Looper.getMainLooper()).idle();
    }

    private <T extends Activity> T launch(Class<T> cls, Object... extras) {
        Intent i = new Intent(app, cls);
        for (int k = 0; k + 1 < extras.length; k += 2) {
            if (extras[k + 1] instanceof Integer) {
                i.putExtra((String) extras[k], (Integer) extras[k + 1]);
            } else {
                i.putExtra((String) extras[k], String.valueOf(extras[k + 1]));
            }
        }
        ActivityController<T> c = Robolectric.buildActivity(cls, i).setup();
        settle(2500);
        return c.get();
    }

    private void shot(Activity a, String name) {
        shot(a.getWindow().getDecorView(), name);
    }

    private void shot(View root, String name) {
        try {
            settle(400);
            int w = root.getWidth();
            int h = root.getHeight();
            if (w == 0 || h == 0) {
                int ws = View.MeasureSpec.makeMeasureSpec(784, View.MeasureSpec.AT_MOST);
                int hs = View.MeasureSpec.makeMeasureSpec(1720, View.MeasureSpec.AT_MOST);
                root.measure(ws, hs);
                root.layout(0, 0, root.getMeasuredWidth(), root.getMeasuredHeight());
                w = root.getWidth();
                h = root.getHeight();
            }
            Bitmap b = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888);
            root.draw(new Canvas(b));
            OUT.mkdirs();
            FileOutputStream out = new FileOutputStream(new File(OUT, name + ".png"));
            b.compress(Bitmap.CompressFormat.PNG, 100, out);
            out.close();
        } catch (Exception e) {
            problems.add("screenshot " + name + ": " + e);
        }
    }

    private void ok(String what, boolean cond) {
        if (cond) {
            passed.add(what);
            System.out.println("  ✓ " + what);
        } else {
            problems.add(what);
            System.out.println("  ✗ " + what);
        }
    }

    private static TextView findText(View v, String text) {
        if (v instanceof TextView && text.equals(String.valueOf(((TextView) v).getText()))) {
            return (TextView) v;
        }
        if (v instanceof ViewGroup) {
            ViewGroup g = (ViewGroup) v;
            for (int i = 0; i < g.getChildCount(); i++) {
                TextView t = findText(g.getChildAt(i), text);
                if (t != null) {
                    return t;
                }
            }
        }
        return null;
    }

    private static boolean containsText(View v, String part) {
        if (v instanceof TextView && String.valueOf(((TextView) v).getText()).contains(part)) {
            return true;
        }
        if (v instanceof ViewGroup) {
            ViewGroup g = (ViewGroup) v;
            for (int i = 0; i < g.getChildCount(); i++) {
                if (containsText(g.getChildAt(i), part)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static <T extends View> T find(View v, Class<T> cls) {
        if (cls.isInstance(v)) {
            return cls.cast(v);
        }
        if (v instanceof ViewGroup) {
            ViewGroup g = (ViewGroup) v;
            for (int i = 0; i < g.getChildCount(); i++) {
                T t = find(g.getChildAt(i), cls);
                if (t != null) {
                    return t;
                }
            }
        }
        return null;
    }

    private boolean click(Activity a, String text) {
        TextView t = findText(a.getWindow().getDecorView(), text);
        if (t == null) {
            t = findContaining(a.getWindow().getDecorView(), text);
        }
        if (t == null) {
            problems.add("no view with text «" + text + "» in " + a.getClass().getSimpleName());
            return false;
        }
        t.performClick();
        settle(1800);
        return true;
    }

    /** Buttons with an icon hold the label after an object-replacement character. */
    private boolean clickContaining(Activity a, String text) {
        TextView t = findContaining(a.getWindow().getDecorView(), text);
        if (t == null) {
            problems.add("no view containing «" + text + "» in " + a.getClass().getSimpleName());
            return false;
        }
        t.performClick();
        settle(1800);
        return true;
    }

    private static TextView findContaining(View v, String part) {
        if (v instanceof TextView && !(v instanceof EditText)) {
            String s = String.valueOf(((TextView) v).getText());
            if (s.startsWith("\uFFFC") && s.substring(1).trim().equals(part)) {
                return (TextView) v;
            }
        }
        if (v instanceof ViewGroup) {
            ViewGroup g = (ViewGroup) v;
            for (int i = 0; i < g.getChildCount(); i++) {
                TextView t = findContaining(g.getChildAt(i), part);
                if (t != null) {
                    return t;
                }
            }
        }
        return null;
    }

    private void scroll(Activity a, int dy) {
        ScrollView sv = find(a.getWindow().getDecorView(), ScrollView.class);
        if (sv != null) {
            sv.scrollBy(0, dy);
        } else {
            ListView lv = find(a.getWindow().getDecorView(), ListView.class);
            if (lv != null) {
                lv.scrollListBy(dy);
            }
        }
        settle(500);
    }

    private void dialogShot(String name) {
        Dialog d = ShadowDialog.getLatestDialog();
        if (d == null || !d.isShowing()) {
            problems.add("no dialog for " + name);
            return;
        }
        shot(d.getWindow().getDecorView(), name);
        d.dismiss();
        settle(200);
    }

    private JSONObject call(String method, String path, JSONObject body) {
        Api.Result r = Api.get(app).callJson(method, path, null, body);
        if (!r.ok) {
            problems.add(method + " " + path + " → " + r.status + " " + (r.error == null ? "" : r.error.describe()));
        }
        return r.ok && r.data instanceof JSONObject ? r.obj() : new JSONObject();
    }

    // ------------------------------------------------------------------ the walk-through

    @Test
    public void everyScreen() throws Exception {
        app = RuntimeEnvironment.getApplication();
        final List<Throwable> crashes = new ArrayList<Throwable>();
        Thread.setDefaultUncaughtExceptionHandler(new Thread.UncaughtExceptionHandler() {
            @Override
            public void uncaughtException(Thread t, Throwable e) {
                crashes.add(e);
                e.printStackTrace();
            }
        });
        Session s = Session.get(app);
        s.signOut();
        s.setServer(SERVER);

        System.out.println("[login]");
        MainActivity main = launch(MainActivity.class);
        shot(main, "01-login");
        List<EditText> edits = new ArrayList<EditText>();
        collect(main.getWindow().getDecorView(), edits);
        ok("login form has 3 inputs", edits.size() == 3);
        edits.get(0).setText(SERVER);
        edits.get(1).setText("admin");
        edits.get(2).setText("wrong-password");
        click(main, "دخول");
        android.app.AlertDialog warn = (android.app.AlertDialog) ShadowDialog.getLatestDialog();
        ok("plain http:// asks for confirmation first", warn != null && warn.isShowing());
        shot(warn.getWindow().getDecorView(), "02a-http-warning");
        warn.getButton(android.content.DialogInterface.BUTTON_POSITIVE).performClick();
        settle(3000);
        ok("wrong password shows the server error", containsText(main.getWindow().getDecorView(), "غير صحيحة") || containsText(main.getWindow().getDecorView(), "خاطئة"));
        shot(main, "02-login-error");
        edits.get(2).setText(System.getenv().getOrDefault("UPSL_ADMIN_PASS", "change-me"));
        clickContaining(main, "دخول");
        settle(3000);
        Intent next = shadowOf(main).getNextStartedActivity();
        ok("login opens the dashboard", next != null && next.getComponent() != null && next.getComponent().getClassName().endsWith("DashboardActivity"));
        ok("session saved with admin permissions", s.isLoggedIn() && s.can("admin.access"));

        // sample data for the screens
        JSONObject u1 = call("POST", "/admin/users", Json.of("username", "noura_test", "email", "noura.test@example.com", "password", "Strong-Pass-2026", "role_id", 4));
        JSONObject u2 = call("POST", "/admin/users", Json.of("username", "moderator_test", "email", "mod.test@example.com", "password", "Strong-Pass-2026", "role_id", 3));
        Api.Result top = Api.get(app).callJson("GET", "/admin/games", Api.q("sort", "downloads", "per_page", "1"), null);
        int gameId = Json.num(top.arr().optJSONObject(0), "id", 0);

        System.out.println("[dashboard & games]");
        DashboardActivity dash = launch(DashboardActivity.class);
        ok("dashboard shows the numbers", containsText(dash.getWindow().getDecorView(), "لعبة"));
        shot(dash, "03-dashboard");
        scroll(dash, 1500);
        shot(dash, "04-dashboard-sections");
        scroll(dash, 1700);
        shot(dash, "05-dashboard-platforms");

        GamesActivity games = launch(GamesActivity.class);
        ListView lv = find(games.getWindow().getDecorView(), ListView.class);
        ok("games list loaded", lv != null && lv.getAdapter() != null && lv.getAdapter().getCount() > 1);
        shot(games, "06-games");
        if (lv != null && lv.getChildCount() > 0) {
            lv.performItemClick(lv.getChildAt(0), 0, 0);
            settle(300);
            ok("tap opens the game", shadowOf(games).getNextStartedActivity() != null);
            lv.getOnItemLongClickListener().onItemLongClick(lv, lv.getChildAt(0), 0, 0);
            lv.getOnItemLongClickListener().onItemLongClick(lv, lv.getChildAt(1), 1, 1);
            settle(300);
            ok("long-press selects games for bulk actions", containsText(games.getWindow().getDecorView(), "محدد"));
            shot(games, "07-games-selection");
        }

        System.out.println("[game]");
        GameActivity game = launch(GameActivity.class, "id", gameId);
        ok("game overview loaded", containsText(game.getWindow().getDecorView(), "البيانات"));
        shot(game, "08-game-overview");
        String[] tabs = {"الوسائط", "الملفات", "الإصدارات", "الروابط", "التوافق"};
        for (int i = 0; i < tabs.length; i++) {
            if (click(game, tabs[i])) {
                shot(game, "09-game-tab-" + (i + 1));
            }
        }
        click(game, "الإصدارات");
        if (click(game, "إضافة إصدار")) {
            dialogShot("10-game-version-dialog");
        }

        GameFormActivity form = launch(GameFormActivity.class, "id", gameId);
        ok("game form filled", containsText(form.getWindow().getDecorView(), "اسم اللعبة"));
        shot(form, "11-game-form");
        List<EditText> fe = new ArrayList<EditText>();
        collect(form.getWindow().getDecorView(), fe);
        fe.get(0).setText("");
        click(form, "حفظ التعديلات");
        ok("client validation blocks an empty title", containsText(form.getWindow().getDecorView(), "اسم اللعبة مطلوب"));
        shot(form, "12-game-form-validation");
        GameFormActivity create = launch(GameFormActivity.class);
        shot(create, "13-game-new");

        System.out.println("[uploads]");
        File tmp = new File(app.getCacheDir(), "upsl-demo-update.bin");
        byte[] data = new byte[6 * 1024 * 1024 + 777];
        new Random(7).nextBytes(data);
        FileOutputStream fo = new FileOutputStream(tmp);
        fo.write(data);
        fo.close();
        DocProvider.file = tmp;
        Robolectric.setupContentProvider(DocProvider.class, "com.upsl.test.docs");
        Uri doc = Uri.parse("content://com.upsl.test.docs/document/1");
        Transfers.Task task = Transfers.get(app).enqueue(app, doc, "game_file", Json.of("game_id", gameId, "file_type", "update", "access_level", "registered"), "اختبار الرفع");
        Transfers.get(app).run(task);
        ok("upload through a content:// document completes (" + task.status + " " + task.error + ")", Transfers.DONE.equals(task.status));
        UploadsActivity uploads = launch(UploadsActivity.class);
        shot(uploads, "14-uploads");
        int newFileId = task.result == null ? 0 : Json.num(task.result, "file_id", 0);

        System.out.println("[catalogue]");
        PlatformsActivity plats = launch(PlatformsActivity.class);
        shot(plats, "15-platforms");
        Api.Result pl = Api.get(app).callJson("GET", "/admin/platforms", null, null);
        PlatformFormActivity pf = launch(PlatformFormActivity.class, "json", pl.arr().optJSONObject(1).toString());
        shot(pf, "16-platform-form");
        CategoriesActivity cats = launch(CategoriesActivity.class);
        shot(cats, "17-categories");
        ListView cl = find(cats.getWindow().getDecorView(), ListView.class);
        if (cl != null && cl.getChildCount() > 0) {
            cl.performItemClick(cl.getChildAt(0), 0, 0);
            settle(500);
            dialogShot("18-category-dialog");
        }
        ImportGamesActivity imp = launch(ImportGamesActivity.class);
        shot(imp, "19-import");
        DuplicatesActivity dups = launch(DuplicatesActivity.class);
        shot(dups, "20-duplicates");

        System.out.println("[users]");
        UsersActivity users = launch(UsersActivity.class);
        ok("users list", containsText(users.getWindow().getDecorView(), "noura_test"));
        shot(users, "21-users");
        UserActivity user = launch(UserActivity.class, "id", Json.num(u2, "id", 0));
        ok("user detail with management actions", containsText(user.getWindow().getDecorView(), "تغيير الدور"));
        shot(user, "22-user");
        RolesActivity roles = launch(RolesActivity.class);
        click(roles, "مشرف المكتبة (50)");
        shot(roles, "23-roles");
        TokensActivity tokens = launch(TokensActivity.class);
        shot(tokens, "24-tokens");
        SecurityActivity sec = launch(SecurityActivity.class);
        shot(sec, "25-security");

        System.out.println("[communication]");
        UpdatesActivity upd = launch(UpdatesActivity.class);
        shot(upd, "26-updates");
        UpdateFormActivity uf = launch(UpdateFormActivity.class);
        shot(uf, "27-update-form");
        NotificationsActivity notes = launch(NotificationsActivity.class);
        shot(notes, "28-notifications");
        NotificationsActivity.compose(notes, null, null);
        settle(300);
        dialogShot("29-notification-compose");

        System.out.println("[files, storage, system]");
        FilesActivity files = launch(FilesActivity.class);
        shot(files, "30-files");
        DownloadsActivity dls = launch(DownloadsActivity.class);
        shot(dls, "31-downloads");
        StorageActivity storage = launch(StorageActivity.class);
        shot(storage, "32-storage");
        SystemActivity sys = launch(SystemActivity.class);
        shot(sys, "33-system");
        LogsActivity logs = launch(LogsActivity.class);
        shot(logs, "34-logs");
        click(logs, "سجل الأخطاء");
        shot(logs, "35-logs-errors");
        BackupsActivity backups = launch(BackupsActivity.class);
        shot(backups, "36-backups");

        System.out.println("[settings & account]");
        SettingsActivity settings = launch(SettingsActivity.class);
        ok("settings form built from the server schema", containsText(settings.getWindow().getDecorView(), "اسم الموقع"));
        shot(settings, "37-settings-general");
        String[] groups = {"الأمان والرفع", "واجهة API", "النسخ الاحتياطي"};
        for (int i = 0; i < groups.length; i++) {
            if (click(settings, groups[i])) {
                shot(settings, "38-settings-" + (i + 1));
            }
        }
        AccountActivity account = launch(AccountActivity.class);
        shot(account, "39-account");

        System.out.println("[moderator account: permission gating]");
        Api.Result modLogin = Api.get(app).callJson("POST", "/auth/login", null, Json.of("login", "moderator_test", "password", "Strong-Pass-2026", "device_name", "robolectric moderator"));
        String adminToken = s.token();
        JSONObject adminUser = s.user();
        s.signIn(Json.str(modLogin.obj(), "token"), Json.obj(modLogin.obj(), "user"));
        ok("moderator signs in (has admin.access, not settings.manage)", s.can("admin.access") && !s.can("settings.manage") && !s.isSuper());
        DashboardActivity modDash = launch(DashboardActivity.class);
        scroll(modDash, 1500);
        ok("dashboard hides sections the role cannot use", !containsText(modDash.getWindow().getDecorView(), "النسخ الاحتياطي") && containsText(modDash.getWindow().getDecorView(), "التحديثات والأخبار"));
        shot(modDash, "40-moderator-dashboard");
        SettingsActivity modSettings = launch(SettingsActivity.class);
        ok("settings screen shows the no-permission state", containsText(modSettings.getWindow().getDecorView(), "ليست لديك صلاحية"));
        shot(modSettings, "41-moderator-settings-locked");
        Api.Result forbidden = Api.get(app).callJson("GET", "/admin/backups", null, null);
        ok("server also refuses the call (403)", forbidden.status == 403);
        Api.get(app).callJson("POST", "/auth/logout", null, new JSONObject());
        s.signIn(adminToken, adminUser);

        // clean up the sample data
        if (newFileId > 0) {
            call("DELETE", "/admin/files/" + newFileId, null);
        }
        call("DELETE", "/admin/users/" + Json.num(u1, "id", 0), null);
        call("DELETE", "/admin/users/" + Json.num(u2, "id", 0), null);
        tmp.delete();

        for (Throwable t : crashes) {
            problems.add("uncaught: " + t);
        }
        System.out.println("\n" + passed.size() + " checks passed, " + problems.size() + " problems");
        for (String p : problems) {
            System.out.println("  PROBLEM: " + p);
        }
        assertTrue("problems: " + problems, problems.isEmpty());
    }

    private static void collect(View v, List<EditText> out) {
        if (v instanceof EditText && v.getVisibility() == View.VISIBLE) {
            out.add((EditText) v);
        }
        if (v instanceof ViewGroup) {
            ViewGroup g = (ViewGroup) v;
            for (int i = 0; i < g.getChildCount(); i++) {
                collect(g.getChildAt(i), out);
            }
        }
    }
}
