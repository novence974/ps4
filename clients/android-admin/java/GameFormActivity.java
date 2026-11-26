package com.upsl.admin;

import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

/** Create or edit a game's data (same fields and rules as the website's form). */
public class GameFormActivity extends Screen {
    private int id;
    private Form form;
    private JSONObject game;
    private List<JSONObject> platforms;
    private List<JSONObject> categories;

    @Override
    protected void build(Bundle saved) {
        id = intExtra("id");
        setTitleText(id > 0 ? "تعديل اللعبة" : "إضافة لعبة");
        if (!require(id > 0 ? "games.edit" : "games.create")) {
            return;
        }
        load();
    }

    private void load() {
        showLoading();
        api.get("/admin/platforms", null, new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                if (!alive()) {
                    return;
                }
                if (!r.ok) {
                    showError(r.error, retry());
                    return;
                }
                platforms = Json.list(r.arr());
                api.get("/categories", null, new Api.Callback() {
                    @Override
                    public void done(Api.Result c) {
                        if (!alive()) {
                            return;
                        }
                        categories = c.ok ? Json.list(c.arr()) : new ArrayList<JSONObject>();
                        if (id > 0) {
                            api.get("/admin/games/" + id, null, new Api.Callback() {
                                @Override
                                public void done(Api.Result g) {
                                    if (!alive()) {
                                        return;
                                    }
                                    if (!g.ok) {
                                        showError(g.error, retry());
                                        return;
                                    }
                                    game = g.obj();
                                    render();
                                }
                            });
                        } else {
                            game = new JSONObject();
                            render();
                        }
                    }
                });
            }
        });
    }

    private Runnable retry() {
        return new Runnable() {
            @Override
            public void run() {
                load();
            }
        };
    }

    private void render() {
        LinearLayout col = page();
        LinearLayout card = Ui.card(this);
        form = new Form(this);
        JSONObject plat = Json.obj(game, "platform");
        String[][] platOpts = new String[platforms.size()][];
        for (int i = 0; i < platforms.size(); i++) {
            JSONObject p = platforms.get(i);
            platOpts[i] = new String[]{String.valueOf(Json.num(p, "id", 0)), Json.str(p, "name", "") + (Json.bool(p, "is_active") ? "" : " (غير نشط)")};
        }
        String[][] catOpts = new String[categories.size()][];
        for (int i = 0; i < categories.size(); i++) {
            JSONObject c = categories.get(i);
            catOpts[i] = new String[]{String.valueOf(Json.num(c, "id", 0)), Json.str(c, "name", "")};
        }
        List<String> gameCats = new ArrayList<String>();
        for (JSONObject c : Json.list(Json.arr(game, "categories"))) {
            gameCats.add(String.valueOf(Json.num(c, "id", 0)));
        }

        form.section("الأساسيات");
        form.text("title", "اسم اللعبة", Json.str(game, "title")).required().max(190);
        form.select("platform_id", "الجهاز", platOpts, plat == null ? (platOpts.length == 1 ? platOpts[0][0] : null) : String.valueOf(Json.num(plat, "id", 0)), false).required();
        form.text("product_code", "رمز المنتج", Json.str(game, "product_code"), "CUSA00341", Form.CODE).max(32)
                .pattern("^[A-Za-z0-9_\\-.]+$", "حروف إنجليزية وأرقام و - _ . فقط");
        form.select("region", "المنطقة", Labels.REGIONS, Json.str(game, "region"), true);
        form.multi("categories", "التصنيفات", catOpts, gameCats);

        form.section("التفاصيل");
        form.text("release_year", "سنة الإصدار", Json.num(game, "release_year", 0) > 0 ? String.valueOf(Json.num(game, "release_year", 0)) : null, "2016", Form.INT)
                .range(1970, 2100);
        form.text("developer", "المطور", Json.str(game, "developer")).max(128);
        form.text("publisher", "الناشر", Json.str(game, "publisher")).max(128);
        form.text("age_rating", "التصنيف العمري", Json.str(game, "age_rating"), "PEGI 16", Form.TEXT).max(24);
        form.text("size", "الحجم", Json.str(game, "size_human"), "مثال: 45.2 GB أو 700 MB", Form.TEXT).max(40).alias("size_bytes");
        form.multi("languages", "اللغات", Labels.LANGUAGES, Json.strings(Json.arr(game, "languages")));
        form.text("version", "الإصدار", Json.str(game, "version"), "1.00", Form.CODE).max(32);
        form.text("edition", "النسخة", Json.str(game, "edition"), "Deluxe Edition", Form.TEXT).max(64);
        form.text("rating", "التقييم من 10", Json.str(game, "rating"), "8.5", Form.DECIMAL).range(0, 10);
        form.select("files_status", "حالة الملفات", Labels.FILES_STATUS, Json.str(game, "files_status", "unavailable"), false)
                .help("تتحدث تلقائياً عند رفع ملف أو حذفه؛ يمكنك اختيار «قريباً» يدوياً.");

        form.section("الوصف والوسائط");
        form.text("description", "الوصف", Json.str(game, "description"), "", Form.MULTILINE).max(20000);
        JSONObject trailer = Json.obj(game, "trailer");
        form.text("trailer_url", "رابط الفيديو التعريفي", trailer == null || "video".equals(Json.str(trailer, "type")) ? null : Json.str(trailer, "url"),
                "https://www.youtube.com/watch?v=…", Form.URL).max(500)
                .help("يوتيوب أو فيميو أو رابط ملف MP4/WebM. لرفع ملف فيديو استخدم تبويب «الوسائط» في صفحة اللعبة.");
        form.text("compatibility_notes", "ملاحظات التوافق", Json.str(game, "compatibility_notes"), "", Form.MULTILINE).max(5000);

        if (session.can("games.publish")) {
            form.section("النشر");
            form.toggle("is_published", "منشورة", Json.bool(game, "is_published"), "تظهر للزوار في المكتبة");
            form.toggle("is_featured", "مميزة", Json.bool(game, "is_featured"), "تظهر في قسم «مختارات» بالواجهة");
        }
        card.addView(form.view());
        col.addView(card);
        col.addView(Ui.button(this, id > 0 ? "حفظ التعديلات" : "إضافة اللعبة", R.drawable.ic_save, Ui.BTN_PRIMARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                save(false);
            }
        }), Ui.fill());
        if (id == 0) {
            col.addView(Ui.small(this, "بعد الإضافة تنتقل إلى صفحة اللعبة لرفع الغلاف واللقطات والملفات."));
        }
    }

    private void save(boolean allowDuplicate) {
        hideKeyboard();
        if (!form.validate()) {
            toast("راجع الحقول المظللة");
            return;
        }
        JSONObject body = form.values();
        JSONObject trailer = Json.obj(game, "trailer");
        if (trailer != null && "video".equals(Json.str(trailer, "type")) && body.isNull("trailer_url")) {
            body.remove("trailer_url");
        }
        JSONArray cats = body.optJSONArray("categories");
        if (cats != null) {
            JSONArray ids = new JSONArray();
            for (int i = 0; i < cats.length(); i++) {
                try {
                    ids.put(Integer.parseInt(cats.optString(i)));
                } catch (NumberFormatException ignored) {
                }
            }
            Json.put(body, "categories", ids);
        }
        if (allowDuplicate) {
            Json.put(body, "allow_duplicate", true);
        }
        busy(id > 0 ? "جارٍ الحفظ…" : "جارٍ الإضافة…");
        Api.Callback cb = new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                busy(null);
                if (!alive()) {
                    return;
                }
                if (r.ok) {
                    markDirty("games", "game:" + id);
                    if (id == 0) {
                        toast("أضيفت اللعبة");
                        open(GameActivity.class, "id", Json.num(r.obj(), "id", 0), "tab", 1);
                    } else {
                        toast("تم الحفظ");
                    }
                    finish();
                    return;
                }
                if ("duplicate_game".equals(r.error.code)) {
                    duplicates(r.error);
                    return;
                }
                String rest = r.error.fields == null ? r.error.getMessage() : form.showErrors(r.error.fields);
                if (rest.length() > 0) {
                    toast(rest);
                }
            }
        };
        if (id > 0) {
            api.put("/admin/games/" + id, body, cb);
        } else {
            api.post("/admin/games", body, cb);
        }
    }

    private void duplicates(Api.Error e) {
        StringBuilder sb = new StringBuilder("توجد لعبة مطابقة على نفس الجهاز:\n");
        JSONArray d = e.raw == null ? null : e.raw.optJSONArray("duplicates");
        for (JSONObject g : Json.list(d)) {
            sb.append("\n• ").append(Json.str(g, "title", "")).append(" (#").append(Json.num(g, "id", 0)).append(")");
            if (Json.str(g, "region") != null) {
                sb.append(" — ").append(Labels.of(Labels.REGIONS, Json.str(g, "region")));
            }
            if (Json.str(g, "product_code") != null) {
                sb.append(" — ").append(Json.str(g, "product_code"));
            }
        }
        sb.append("\n\nإذا كانت هذه نسخة مختلفة (منطقة أو إصدار آخر) يمكنك الحفظ رغم التشابه.");
        Dialogs.confirm(this, "لعبة مكررة؟", sb.toString(), "حفظ رغم التكرار", false, new Runnable() {
            @Override
            public void run() {
                save(true);
            }
        });
    }
}
