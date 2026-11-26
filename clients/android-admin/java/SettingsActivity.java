package com.upsl.admin;

import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;
import android.widget.TextView;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

/**
 * Site settings, built from the schema the server sends (each group needs its own permission):
 * general (site, maintenance, music, e-mail), security & uploads, API, backups.
 */
public class SettingsActivity extends Screen {
    private JSONObject data;
    private final List<String> groups = new ArrayList<String>();
    private int tab;
    private Form form;

    @Override
    protected void build(Bundle saved) {
        setTitleText("الإعدادات");
        if (!require("settings.manage", "security.manage", "api.manage", "backups.manage")) {
            return;
        }
        load();
    }

    private void load() {
        showLoading();
        api.get("/admin/settings", null, new Api.Callback() {
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
                data = r.obj();
                groups.clear();
                for (String g : new String[]{"general", "security", "api", "backups"}) {
                    if (Json.obj(data, "groups") != null && Json.obj(data, "groups").has(g)) {
                        groups.add(g);
                    }
                }
                tab = Math.min(tab, Math.max(0, groups.size() - 1));
                render();
            }
        });
    }

    private void render() {
        LinearLayout col = page();
        List<String> labels = new ArrayList<String>();
        for (String g : groups) {
            labels.add(Labels.settingsGroup(g));
        }
        col.addView(Ui.pillRow(this, labels, tab, new Ui.IntCallback() {
            @Override
            public void done(int value) {
                tab = value;
                render();
            }
        }));
        if (groups.isEmpty()) {
            return;
        }
        final String group = groups.get(tab);
        JSONArray fields = Json.obj(data, "groups").optJSONArray(group);
        LinearLayout card = Ui.card(this);
        form = new Form(this);
        for (JSONObject f : Json.list(fields)) {
            addField(f);
        }
        form.onChange(new Runnable() {
            @Override
            public void run() {
                dependencies();
            }
        });
        dependencies();
        card.addView(form.view());
        col.addView(card);
        col.addView(Ui.button(this, "حفظ «" + Labels.settingsGroup(group) + "»", R.drawable.ic_save, Ui.BTN_PRIMARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                save(group);
            }
        }), Ui.fill());
        if ("general".equals(group)) {
            extrasGeneral(col);
        }
        if ("security".equals(group)) {
            col.addView(Ui.space(this, 10));
            col.addView(Ui.small(this, "امتدادات ملفات خطرة مثل php وexe وsh وhtml مرفوضة دائماً مهما كتبت هنا."));
        }
    }

    private void addField(JSONObject f) {
        String key = Json.str(f, "key", "");
        String type = Json.str(f, "type", "string");
        String label = Labels.setting(key);
        Object value = f.opt("value");
        String sval = value == null || value == JSONObject.NULL ? null : String.valueOf(value);
        if ("bool".equals(type)) {
            form.toggle(key, label, Json.bool(f, "value"), null);
        } else if ("int".equals(type)) {
            form.text(key, label, sval, "", Form.INT).range(Json.num(f, "min", 0), Json.num(f, "max", Integer.MAX_VALUE)).required()
                    .help("من " + Json.num(f, "min", 0) + " إلى " + Fmt.num(Json.num(f, "max", 0)));
        } else if ("enum".equals(type)) {
            String[][] named = Labels.settingOptions(key);
            List<String> opts = Json.strings(Json.arr(f, "options"));
            String[][] o = new String[opts.size()][];
            for (int i = 0; i < opts.size(); i++) {
                o[i] = new String[]{opts.get(i), named == null ? opts.get(i) : Labels.of(named, opts.get(i))};
            }
            form.select(key, label, o, sval, false).required();
        } else if ("secret".equals(type)) {
            form.text(key, label, null, Json.bool(f, "is_set") ? "محفوظة — اتركها فارغة للإبقاء عليها" : "غير محددة", Form.PASSWORD);
        } else if ("url".equals(type)) {
            form.text(key, label, sval, "https://cdn.example.com", Form.URL).max(500);
        } else if ("email".equals(type)) {
            form.text(key, label, sval, "noreply@example.com", Form.EMAIL).required();
        } else {
            int max = Json.num(f, "max_length", 255);
            boolean multi = key.endsWith("message") || key.endsWith("extensions") || key.endsWith("origins");
            form.text(key, label, sval, "", multi ? Form.MULTILINE : Form.TEXT).max(max);
        }
    }

    private void dependencies() {
        if (form.has("mail.driver")) {
            boolean smtp = "smtp".equals(form.str("mail.driver"));
            for (String k : new String[]{"mail.host", "mail.port", "mail.encryption", "mail.username", "mail.password"}) {
                if (form.has(k)) {
                    form.field(k).visible(smtp);
                }
            }
        }
        if (form.has("site.maintenance") && form.has("site.maintenance_message")) {
            form.field("site.maintenance_message").visible(form.bool("site.maintenance"));
        }
        if (form.has("music.enabled") && form.has("music.default_volume")) {
            form.field("music.default_volume").visible(form.bool("music.enabled"));
        }
    }

    private void save(final String group) {
        hideKeyboard();
        if (!form.validate()) {
            return;
        }
        final JSONObject body = form.values();
        if (form.has("site.maintenance") && form.bool("site.maintenance") && !Json.bool(findValue("site.maintenance"), "value")) {
            Dialogs.confirm(this, "تفعيل وضع الصيانة", "سيرى الزوار صفحة الصيانة بدلاً من المكتبة حتى تلغيه. المدراء يواصلون الدخول.", "تفعيل وحفظ", true, new Runnable() {
                @Override
                public void run() {
                    put(group, body);
                }
            });
            return;
        }
        put(group, body);
    }

    private JSONObject findValue(String key) {
        for (String g : groups) {
            for (JSONObject f : Json.list(Json.obj(data, "groups").optJSONArray(g))) {
                if (key.equals(Json.str(f, "key"))) {
                    return f;
                }
            }
        }
        return null;
    }

    private void put(String group, JSONObject body) {
        busy("جارٍ الحفظ…");
        api.put("/admin/settings/" + group, body, new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                busy(null);
                if (!alive()) {
                    return;
                }
                if (r.ok) {
                    toast("حُفظت الإعدادات");
                    load();
                    return;
                }
                if (r.error.fields != null) {
                    JSONObject mapped = new JSONObject();
                    for (String k : Json.keys(r.error.fields)) {
                        // the server reports "site_name"; the form uses "site.name"
                        String dotted = k.contains(".") ? k : k.replaceFirst("_", ".");
                        Json.put(mapped, form.has(dotted) ? dotted : k, r.error.fields.opt(k));
                    }
                    String rest = form.showErrors(mapped);
                    if (rest.length() > 0) {
                        toast(rest);
                    }
                } else {
                    fail(r);
                }
            }
        });
    }

    private void extrasGeneral(LinearLayout col) {
        if (!session.can("settings.manage")) {
            return;
        }
        col.addView(Ui.space(this, 16));
        LinearLayout music = Ui.card(this);
        music.addView(Ui.h2(this, "الموسيقى الخلفية للموقع"));
        String track = Json.str(data, "music_track");
        TextView t = Ui.muted(this, track == null ? "لا يوجد ملف موسيقى." : "الملف الحالي: " + track);
        music.addView(t);
        music.addView(Ui.space(this, 10));
        List<View> b = new ArrayList<View>();
        b.add(Ui.button(this, "رفع MP3/OGG", R.drawable.ic_music, Ui.BTN_SECONDARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                pickFile(new String[]{"audio/mpeg", "audio/ogg", "application/ogg"}, new Ui.Callback<Uri>() {
                    @Override
                    public void done(Uri uri) {
                        askNotifications();
                        Transfers.get(SettingsActivity.this).enqueue(SettingsActivity.this, uri, "music", new JSONObject(), "موسيقى الموقع");
                        toast("بدأ رفع الملف؛ تابع التقدم في «عمليات الرفع»");
                    }
                });
            }
        }));
        if (track != null) {
            b.add(Ui.button(this, "إزالة", R.drawable.ic_trash, Ui.BTN_DANGER, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    api.delete("/admin/settings/music", new Api.Callback() {
                        @Override
                        public void done(Api.Result r) {
                            if (!alive()) {
                                return;
                            }
                            if (r.ok) {
                                toast("أزيلت الموسيقى");
                                load();
                            } else {
                                fail(r);
                            }
                        }
                    });
                }
            }));
        }
        music.addView(Ui.grid(this, b, 2));
        col.addView(music);

        LinearLayout mail = Ui.card(this);
        mail.addView(Ui.h2(this, "اختبار البريد"));
        mail.addView(Ui.muted(this, "يرسل رسالة اختبار إلى بريد حسابك بالإعدادات المحفوظة (الطريقة الحالية: " + Json.str(data, "mail_driver_active", "?") + ")."));
        mail.addView(Ui.space(this, 10));
        mail.addView(Ui.button(this, "إرسال رسالة اختبار", R.drawable.ic_mail, Ui.BTN_SECONDARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                busy("جارٍ الإرسال…");
                api.post("/admin/settings/mail-test", new JSONObject(), new Api.Callback() {
                    @Override
                    public void done(Api.Result r) {
                        busy(null);
                        if (!alive()) {
                            return;
                        }
                        if (r.ok) {
                            Dialogs.info(SettingsActivity.this, "أُرسلت الرسالة", "إلى: " + Json.str(r.obj(), "sent_to", "") + "\nالطريقة: " + Json.str(r.obj(), "driver", "")
                                    + ("log".equals(Json.str(r.obj(), "driver")) ? "\n\nملاحظة: طريقة «سجل فقط» تحفظ الرسالة في سجل الخادم ولا ترسلها فعلياً." : ""));
                        } else {
                            fail(r);
                        }
                    }
                });
            }
        }), Ui.fill());
        col.addView(mail);
    }
}
