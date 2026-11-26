package com.upsl.admin;

import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;

import org.json.JSONObject;

import java.util.List;

/** Groups of games that share a normalised title or a product code on the same platform. */
public class DuplicatesActivity extends Screen {
    @Override
    protected void build(Bundle saved) {
        setTitleText("الألعاب المكررة");
        if (!require("games.edit")) {
            return;
        }
        addAction(R.drawable.ic_refresh, "تحديث", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                load();
            }
        });
        load();
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (content != null && consumeDirty("games")) {
            load();
        }
    }

    private void load() {
        showLoading();
        api.get("/admin/duplicates", null, new Api.Callback() {
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
                render(Json.list(r.arr()));
            }
        });
    }

    private void render(List<JSONObject> groups) {
        if (groups.isEmpty()) {
            setBody(Ui.state(this, R.drawable.ic_ok, Ui.OK, "لا توجد ألعاب مكررة", "لا توجد ألعاب بنفس الاسم أو رمز المنتج على نفس الجهاز.", null, null));
            return;
        }
        LinearLayout col = page();
        col.addView(Ui.small(this, "تُعرض الألعاب التي تتشابه في الاسم أو رمز المنتج على الجهاز نفسه. قد تكون نسخاً لمناطق مختلفة؛ افتح كل لعبة وقرر: تعديل أو حذف."));
        col.addView(Ui.space(this, 10));
        for (JSONObject g : groups) {
            LinearLayout c = Ui.card(this);
            LinearLayout head = Ui.hbox(this);
            boolean byCode = "code".equals(Json.str(g, "kind"));
            head.addView(Ui.text(this, (byCode ? "رمز المنتج: " : "الاسم: ") + Json.str(g, "ref", ""), 14.5f, Ui.INK, true), Ui.weight(1));
            head.addView(Ui.chip(this, Json.str(g, "short_name", "?") + " • " + Json.num(g, "c", 0), byCode ? Ui.WARN : Ui.ACCENT2));
            c.addView(head);
            c.addView(Ui.space(this, 8));
            for (final JSONObject game : Json.list(Json.arr(g, "games"))) {
                boolean pub = Json.bool(game, "is_published");
                String sub = "#" + Json.num(game, "id", 0) + " • " + Labels.of(Labels.REGIONS, Json.str(game, "region"))
                        + (Json.str(game, "product_code") != null ? " • " + Json.str(game, "product_code") : "")
                        + (Json.num(game, "release_year", 0) > 0 ? " • " + Json.num(game, "release_year", 0) : "")
                        + " • أضيفت " + Fmt.dateOnly(Json.str(game, "created_at"));
                LinearLayout r = Ui.row(this, null, Json.str(game, "title", ""), sub, Ui.chip(this, pub ? "منشورة" : "مسودة", pub ? Ui.OK : Ui.WARN));
                r.setOnClickListener(new View.OnClickListener() {
                    @Override
                    public void onClick(View v) {
                        open(GameActivity.class, "id", Json.num(game, "id", 0));
                    }
                });
                c.addView(r);
            }
            col.addView(c);
        }
    }
}
