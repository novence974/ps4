package com.upsl.admin;

import android.os.Bundle;
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.widget.LinearLayout;
import android.widget.TextView;

import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

/** Write or edit a news item (optionally linked to a game, platform and version). */
public class UpdateFormActivity extends Screen {
    private JSONObject update;
    private int id;
    private Form form;
    private int gameId;
    private String gameTitle;
    private List<JSONObject> platforms = new ArrayList<JSONObject>();
    private List<JSONObject> versions = new ArrayList<JSONObject>();
    private TextView gamePicker;
    private LinearLayout versionHolder;

    @Override
    protected void build(Bundle saved) {
        update = extraJson(getIntent(), "json");
        if (update == null) {
            update = new JSONObject();
        }
        id = Json.num(update, "id", 0);
        JSONObject g = Json.obj(update, "game");
        gameId = g == null ? 0 : Json.num(g, "id", 0);
        gameTitle = g == null ? null : Json.str(g, "title");
        setTitleText(id > 0 ? "تعديل الخبر" : "خبر جديد");
        if (!require("updates.manage")) {
            return;
        }
        showLoading();
        api.get("/admin/platforms", null, new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                if (!alive()) {
                    return;
                }
                platforms = r.ok ? Json.list(r.arr()) : platforms;
                if (gameId > 0) {
                    loadVersions(true);
                } else {
                    render();
                }
            }
        });
    }

    private void loadVersions(final boolean first) {
        api.get("/admin/games/" + gameId + "/versions", null, new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                if (!alive()) {
                    return;
                }
                versions = r.ok ? Json.list(r.arr()) : new ArrayList<JSONObject>();
                if (first) {
                    render();
                } else {
                    renderVersions(null);
                }
            }
        });
    }

    private void render() {
        LinearLayout col = page();
        LinearLayout card = Ui.card(this);
        form = new Form(this);
        form.select("type", "النوع", Labels.UPDATE_TYPES, Json.str(update, "type", "announcement"), false).required();
        form.text("title", "العنوان", Json.str(update, "title")).required().max(190);
        form.text("body", "التفاصيل", Json.str(update, "body"), "", Form.MULTILINE).max(10000);

        form.section("مرتبط بـ (اختياري)");
        TextView gl = Ui.text(this, "اللعبة", 13, Ui.MIST, true);
        gl.setPadding(0, Ui.dp(this, 4), 0, Ui.dp(this, 6));
        form.addView(gl);
        LinearLayout gr = Ui.hbox(this);
        gamePicker = Ui.text(this, "", 15, Ui.INK, false);
        gamePicker.setMinHeight(Ui.dp(this, 48));
        gamePicker.setGravity(Gravity.CENTER_VERTICAL | Gravity.START);
        gamePicker.setPadding(Ui.dp(this, 14), 0, Ui.dp(this, 14), 0);
        gamePicker.setBackground(Ui.ripple(this, Ui.outline(this, Ui.FIELD, Ui.STROKE_STRONG, 14), 14));
        gamePicker.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                searchGame();
            }
        });
        gr.addView(gamePicker, Ui.weight(1));
        gr.addView(Ui.iconButton(this, R.drawable.ic_x, "إزالة اللعبة", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                gameId = 0;
                gameTitle = null;
                versions = new ArrayList<JSONObject>();
                refreshGame();
                renderVersions(null);
            }
        }));
        form.addView(gr);
        form.addView(Ui.space(this, 10));
        refreshGame();

        String[][] po = new String[platforms.size()][];
        String platSel = null;
        for (int i = 0; i < platforms.size(); i++) {
            JSONObject p = platforms.get(i);
            po[i] = new String[]{String.valueOf(Json.num(p, "id", 0)), Json.str(p, "name", "")};
            if (Json.str(update, "platform") != null && Json.str(update, "platform").equals(Json.str(p, "short_name"))) {
                platSel = po[i][0];
            }
        }
        form.select("platform_id", "الجهاز", po, platSel, true);
        versionHolder = Ui.vbox(this);
        form.addView(versionHolder);

        form.section("النشر");
        form.toggle("is_published", "منشور", id == 0 || Json.bool(update, "is_published"), "يظهر في صفحة «التحديثات» بالموقع والتطبيقات");
        form.toggle("notify_favorites", "إشعار المهتمين باللعبة", false, "عند النشر لأول مرة: إشعار لكل من أضاف اللعبة المرتبطة إلى مفضلته");
        card.addView(form.view());
        col.addView(card);
        renderVersions(Json.str(update, "version"));

        col.addView(Ui.button(this, id > 0 ? "حفظ" : "نشر الخبر", R.drawable.ic_save, Ui.BTN_PRIMARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                save();
            }
        }), Ui.fill());
        if (id > 0) {
            col.addView(Ui.space(this, 10));
            col.addView(Ui.button(this, "حذف الخبر", R.drawable.ic_trash, Ui.BTN_DANGER, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    Dialogs.confirm(UpdateFormActivity.this, "حذف الخبر", "سيُحذف هذا الخبر من الموقع والتطبيقات.", "حذف", true, new Runnable() {
                        @Override
                        public void run() {
                            busy("جارٍ الحذف…");
                            api.delete("/admin/updates/" + id, new Api.Callback() {
                                @Override
                                public void done(Api.Result r) {
                                    busy(null);
                                    if (!alive()) {
                                        return;
                                    }
                                    if (r.ok) {
                                        markDirty("updates");
                                        toast("حُذف الخبر");
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

    /** Version select of the linked game (rebuilt when the game changes). */
    private void renderVersions(String preselectName) {
        versionHolder.removeAllViews();
        if (versions.isEmpty()) {
            return;
        }
        Form vf = new Form(this);
        String[][] vo = new String[versions.size()][];
        String sel = null;
        for (int i = 0; i < versions.size(); i++) {
            JSONObject v = versions.get(i);
            vo[i] = new String[]{String.valueOf(Json.num(v, "id", 0)), Json.str(v, "version", "")};
            if (preselectName != null && preselectName.equals(Json.str(v, "version"))) {
                sel = vo[i][0];
            }
        }
        vf.select("version_id", "الإصدار", vo, sel, true);
        versionHolder.addView(vf.view());
        versionHolder.setTag(vf);
    }

    private void refreshGame() {
        gamePicker.setText(gameId > 0 ? gameTitle + "  #" + gameId : "اختر لعبة…");
        gamePicker.setTextColor(gameId > 0 ? Ui.INK : Ui.FAINT);
    }

    private void searchGame() {
        Dialogs.prompt(this, "البحث عن لعبة", "اكتب جزءاً من الاسم", "", InputType.TYPE_CLASS_TEXT, new Ui.Callback<String>() {
            @Override
            public void done(String q) {
                if (q.length() == 0) {
                    return;
                }
                busy("جارٍ البحث…");
                api.get("/admin/games", Api.q("q", q, "per_page", "30"), new Api.Callback() {
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
                        final List<JSONObject> games = Json.list(r.arr());
                        if (games.isEmpty()) {
                            toast("لا توجد نتائج");
                            return;
                        }
                        List<String> labels = new ArrayList<String>();
                        for (JSONObject g : games) {
                            labels.add(Json.str(g, "title", "") + " — " + Json.str(Json.obj(g, "platform"), "short_name", ""));
                        }
                        Dialogs.choose(UpdateFormActivity.this, "اختر اللعبة", labels, -1, new Ui.IntCallback() {
                            @Override
                            public void done(int which) {
                                JSONObject g = games.get(which);
                                gameId = Json.num(g, "id", 0);
                                gameTitle = Json.str(g, "title", "");
                                refreshGame();
                                JSONObject plat = Json.obj(g, "platform");
                                if (plat != null && form.str("platform_id") == null) {
                                    form.set("platform_id", String.valueOf(Json.num(plat, "id", 0)));
                                }
                                loadVersions(false);
                            }
                        });
                    }
                });
            }
        });
    }

    private void save() {
        hideKeyboard();
        if (!form.validate()) {
            return;
        }
        JSONObject body = form.values();
        Json.put(body, "game_id", gameId > 0 ? gameId : null);
        String pid = form.str("platform_id");
        Json.put(body, "platform_id", pid == null ? null : Integer.valueOf(pid));
        Object vf = versionHolder.getTag();
        String vid = vf instanceof Form && versionHolder.getChildCount() > 0 ? ((Form) vf).str("version_id") : null;
        Json.put(body, "version_id", vid == null ? null : Integer.valueOf(vid));
        busy("جارٍ الحفظ…");
        Api.Callback cb = new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                busy(null);
                if (!alive()) {
                    return;
                }
                if (r.ok) {
                    markDirty("updates");
                    toast("تم الحفظ");
                    finish();
                    return;
                }
                String rest = r.error.fields == null ? r.error.getMessage() : form.showErrors(r.error.fields);
                if (rest.length() > 0) {
                    toast(rest);
                }
            }
        };
        if (id > 0) {
            api.put("/admin/updates/" + id, body, cb);
        } else {
            api.post("/admin/updates", body, cb);
        }
    }
}
