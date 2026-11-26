package com.upsl.admin;

import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;

import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;
import java.util.Map;

/** Every hosted file of the library (all games), searchable by name, game or SHA-256. */
public class FilesActivity extends ListScreen {
    private final int[] type = {0};

    @Override
    protected String endpoint() {
        return "/admin/files";
    }

    @Override
    protected String searchHint() {
        return "اسم الملف أو اللعبة أو بصمة SHA-256";
    }

    @Override
    protected void query(Map<String, String> q) {
        if (type[0] > 0) {
            q.put("type", Labels.FILE_TYPES[type[0] - 1][0]);
        }
    }

    @Override
    protected void build(Bundle saved) {
        setTitleText("الملفات المستضافة");
        if (!require("files.manage")) {
            return;
        }
        super.build(saved);
    }

    @Override
    protected void buildHeader(LinearLayout header) {
        List<String> labels = new ArrayList<String>();
        labels.add("كل الأنواع");
        labels.addAll(Labels.labels(Labels.FILE_TYPES));
        header.addView(filters(labels, type, new Runnable() {
            @Override
            public void run() {
                reload();
            }
        }));
    }

    @Override
    protected View row(JSONObject f, int position) {
        boolean active = Json.bool(f, "is_active");
        JSONObject game = Json.obj(f, "game");
        String sub = Json.str(game, "title", "") + "\n" + Json.str(f, "type_label", "") + " • " + Json.str(f, "size_human", "") + " • "
                + Labels.of(Labels.ACCESS, Json.str(f, "access_level")) + " • " + Fmt.num(Json.lng(f, "downloads", 0)) + " تنزيل";
        return Ui.row(this, Ui.iconPlate(this, R.drawable.ic_file, active ? Ui.ACCENT2 : Ui.FAINT, 40), Json.str(f, "name", ""), sub,
                Ui.chip(this, active ? "مفعّل" : "معطّل", active ? Ui.OK : Ui.MIST));
    }

    @Override
    protected void onItem(JSONObject f) {
        open(GameActivity.class, "id", Json.num(Json.obj(f, "game"), "id", 0), "tab", 2);
    }

    @Override
    protected String emptyTitle() {
        return "لا توجد ملفات";
    }

    @Override
    protected int emptyIcon() {
        return R.drawable.ic_files;
    }
}
