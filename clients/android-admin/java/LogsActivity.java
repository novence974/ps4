package com.upsl.admin;

import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.Arrays;
import java.util.Map;

/** Activity log (who did what) and the server's error log. */
public class LogsActivity extends ListScreen {
    private final int[] tab = {0};
    private final int[] level = {0};
    private View levelRow;

    @Override
    protected String endpoint() {
        return tab[0] == 0 ? "/admin/logs" : "/admin/logs/errors";
    }

    @Override
    protected String searchHint() {
        return "ابحث في الوصف أو اسم المستخدم أو عنوان IP";
    }

    @Override
    protected void query(Map<String, String> q) {
        if (tab[0] == 1) {
            q.remove("q");
            q.put("per_page", "300");
            if (level[0] > 0) {
                q.put("level", Labels.LOG_LEVELS[level[0]][0]);
            }
        }
    }

    @Override
    protected int perPage() {
        return 50;
    }

    @Override
    protected void build(Bundle saved) {
        setTitleText("السجلات");
        if (!require("logs.view")) {
            return;
        }
        super.build(saved);
    }

    @Override
    protected void buildHeader(LinearLayout header) {
        header.addView(filters(Arrays.asList("سجل النشاط", "سجل الأخطاء"), tab, new Runnable() {
            @Override
            public void run() {
                levelRow.setVisibility(tab[0] == 1 ? View.VISIBLE : View.GONE);
                search.setVisibility(tab[0] == 0 ? View.VISIBLE : View.GONE);
                reload();
            }
        }));
        levelRow = filters(Labels.labels(Labels.LOG_LEVELS), level, new Runnable() {
            @Override
            public void run() {
                reload();
            }
        });
        levelRow.setVisibility(View.GONE);
        header.addView(levelRow);
    }

    @Override
    protected JSONArray extractArray(Api.Result r) {
        return r.arr();
    }

    @Override
    protected View row(JSONObject l, int position) {
        if (tab[0] == 1) {
            String lv = Json.str(l, "level", "info");
            int color = "error".equals(lv) ? Ui.BAD : "warning".equals(lv) ? Ui.WARN : Ui.MIST;
            String sub = Fmt.date(Json.str(l, "at")) + (Json.str(l, "method") != null ? " • " + Json.str(l, "method") + " " + Json.str(l, "path", "") : "")
                    + (l.opt("context") instanceof JSONObject ? "\n" + l.opt("context").toString() : "");
            return Ui.row(this, Ui.iconPlate(this, "error".equals(lv) ? R.drawable.ic_err : R.drawable.ic_warn, color, 36), Json.str(l, "message", ""), sub, null);
        }
        String sub = (Json.str(l, "description") != null ? Json.str(l, "description") + "\n" : "")
                + (Json.str(l, "user") != null ? Json.str(l, "user") : "النظام") + " • " + Fmt.ltr(Json.str(l, "ip", "")) + " • " + Fmt.ago(Json.str(l, "at"));
        return Ui.row(this, Ui.iconPlate(this, R.drawable.ic_history, Ui.ACCENT2, 36), Json.str(l, "label", Json.str(l, "action", "")), sub, null);
    }

    @Override
    protected void onItem(JSONObject l) {
        Dialogs.info(this, tab[0] == 1 ? "تفاصيل الخطأ" : Json.str(l, "label", "نشاط"), l.toString().replace(",\"", ",\n\""));
    }

    @Override
    protected String emptyTitle() {
        return tab[0] == 1 ? "لا توجد أخطاء مسجلة" : "لا يوجد نشاط";
    }

    @Override
    protected int emptyIcon() {
        return R.drawable.ic_history;
    }
}
