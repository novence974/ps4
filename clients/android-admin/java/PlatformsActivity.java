package com.upsl.admin;

import android.os.Bundle;
import android.view.View;

import org.json.JSONObject;

/** Consoles / platforms of the library (PS1 … PS5, PSP, PS Vita…). */
public class PlatformsActivity extends ListScreen {
    @Override
    protected String endpoint() {
        return "/admin/platforms";
    }

    @Override
    protected void build(Bundle saved) {
        setTitleText("الأجهزة");
        if (session.can("platforms.manage")) {
            addAction(R.drawable.ic_plus, "إضافة جهاز", new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    open(PlatformFormActivity.class);
                }
            });
        }
        super.build(saved);
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (list != null && consumeDirty("platforms")) {
            reload();
        }
    }

    @Override
    protected View row(JSONObject p, int position) {
        int color = Ui.parseColor(Json.str(p, "color"), Ui.ACCENT);
        String logo = ImageLoader.pick(Json.obj(p, "logo"), "md", "md_png");
        View lead = logo != null ? Ui.remote(this, logo, 44, 44, 12) : Ui.iconPlate(this, R.drawable.ic_tv, color, 44);
        boolean active = Json.bool(p, "is_active");
        String sub = Json.str(p, "short_name", "") + " • " + Fmt.count(Json.num(p, "games_count", 0), "لعبة واحدة", "لعبتان", "ألعاب")
                + (Json.num(p, "release_year", 0) > 0 ? " • " + Json.num(p, "release_year", 0) : "")
                + (Json.str(p, "supported_formats") != null ? "\nالصيغ: " + Json.str(p, "supported_formats") : "");
        return Ui.row(this, lead, Json.str(p, "name", ""), sub, Ui.chip(this, active ? "نشط" : "مخفي", active ? Ui.OK : Ui.MIST));
    }

    @Override
    protected void onItem(JSONObject p) {
        if (session.can("platforms.manage")) {
            open(PlatformFormActivity.class, "json", p.toString());
        } else {
            toast("تعديل الأجهزة يتطلب صلاحية «إدارة الأجهزة»");
        }
    }

    @Override
    protected String emptyTitle() {
        return "لا توجد أجهزة";
    }

    @Override
    protected int emptyIcon() {
        return R.drawable.ic_tv;
    }
}
