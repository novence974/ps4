package com.upsl.admin;

import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;

import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;
import java.util.Map;

/** News feed of the site: new games, game updates, patches, announcements and maintenance notes. */
public class UpdatesActivity extends ListScreen {
    private final int[] type = {0};

    @Override
    protected String endpoint() {
        return "/admin/updates";
    }

    @Override
    protected void query(Map<String, String> q) {
        if (type[0] > 0) {
            q.put("type", Labels.UPDATE_TYPES[type[0] - 1][0]);
        }
    }

    @Override
    protected void build(Bundle saved) {
        setTitleText("التحديثات والأخبار");
        if (!require("updates.manage")) {
            return;
        }
        addAction(R.drawable.ic_plus, "خبر جديد", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                open(UpdateFormActivity.class);
            }
        });
        super.build(saved);
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (list != null && consumeDirty("updates")) {
            reload();
        }
    }

    @Override
    protected void buildHeader(LinearLayout header) {
        List<String> labels = new ArrayList<String>();
        labels.add("الكل");
        labels.addAll(Labels.labels(Labels.UPDATE_TYPES));
        header.addView(filters(labels, type, new Runnable() {
            @Override
            public void run() {
                reload();
            }
        }));
    }

    static int icon(String type) {
        if ("game_added".equals(type)) {
            return R.drawable.ic_sparkles;
        }
        if ("game_updated".equals(type)) {
            return R.drawable.ic_refresh;
        }
        if ("patch".equals(type)) {
            return R.drawable.ic_package;
        }
        if ("maintenance".equals(type)) {
            return R.drawable.ic_settings;
        }
        return R.drawable.ic_megaphone;
    }

    @Override
    protected View row(JSONObject u, int position) {
        boolean pub = Json.bool(u, "is_published");
        JSONObject game = Json.obj(u, "game");
        String sub = Json.str(u, "type_label", "") + " • " + Fmt.dateOnly(Json.str(u, "published_at"))
                + (game != null ? "\n" + Json.str(game, "title", "") : "")
                + (Json.str(u, "version") != null ? " — " + Json.str(u, "version") : "");
        return Ui.row(this, Ui.iconPlate(this, icon(Json.str(u, "type")), Ui.ACCENT2, 40), Json.str(u, "title", ""), sub,
                Ui.chip(this, pub ? "منشور" : "مسودة", pub ? Ui.OK : Ui.WARN));
    }

    @Override
    protected void onItem(JSONObject u) {
        open(UpdateFormActivity.class, "json", u.toString());
    }

    @Override
    protected String emptyTitle() {
        return "لا توجد تحديثات";
    }

    @Override
    protected int emptyIcon() {
        return R.drawable.ic_megaphone;
    }
}
