package com.upsl.admin;

import android.os.Bundle;
import android.view.View;

import org.json.JSONObject;

/** Download log: who downloaded which file, when and from which address. */
public class DownloadsActivity extends ListScreen {
    @Override
    protected String endpoint() {
        return "/admin/downloads";
    }

    @Override
    protected int perPage() {
        return 50;
    }

    @Override
    protected void build(Bundle saved) {
        setTitleText("سجل التنزيلات");
        if (!require("downloads.view")) {
            return;
        }
        super.build(saved);
    }

    @Override
    protected View row(JSONObject d, int position) {
        JSONObject game = Json.obj(d, "game");
        String sub = (Json.str(d, "file") != null ? Json.str(d, "file") : "") + (Json.lng(d, "size_bytes", 0) > 0 ? " • " + Fmt.bytes(Json.lng(d, "size_bytes", 0)) : "")
                + "\n" + (Json.str(d, "user") != null ? Json.str(d, "user") : "زائر") + " • " + Fmt.ltr(Json.str(d, "ip", "")) + " • " + Fmt.ago(Json.str(d, "at"));
        return Ui.row(this, Ui.iconPlate(this, R.drawable.ic_download, Ui.ACCENT3, 38), Json.str(game, "title", ""), sub, null);
    }

    @Override
    protected void onItem(JSONObject d) {
        open(GameActivity.class, "id", Json.num(Json.obj(d, "game"), "id", 0));
    }

    @Override
    protected String emptyTitle() {
        return "لا توجد تنزيلات بعد";
    }

    @Override
    protected int emptyIcon() {
        return R.drawable.ic_download;
    }
}
