package com.upsl.admin;

import android.app.AlertDialog;
import android.os.Bundle;
import android.view.View;

import org.json.JSONObject;

/** Game categories (genres) with icon and colour; edited in a dialog. */
public class CategoriesActivity extends ListScreen {
    @Override
    protected String endpoint() {
        return "/categories";
    }

    @Override
    protected void build(Bundle saved) {
        setTitleText("التصنيفات");
        if (session.can("categories.manage")) {
            addAction(R.drawable.ic_plus, "إضافة تصنيف", new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    edit(null);
                }
            });
        }
        super.build(saved);
    }

    @Override
    protected View row(JSONObject c, int position) {
        int color = Ui.parseColor(Json.str(c, "color"), Ui.ACCENT);
        String sub = (Json.str(c, "name_en") != null ? Json.str(c, "name_en") + " • " : "") + Fmt.ltr(Json.str(c, "slug", "")) + "\n"
                + Fmt.count(Json.num(c, "games_count", 0), "لعبة منشورة واحدة", "لعبتان منشورتان", "ألعاب منشورة");
        return Ui.row(this, Ui.iconPlate(this, Labels.categoryIcon(Json.str(c, "icon")), color, 42), Json.str(c, "name", ""), sub, null);
    }

    @Override
    protected void onItem(JSONObject c) {
        if (session.can("categories.manage")) {
            edit(c);
        }
    }

    private void edit(final JSONObject c) {
        final Form f = new Form(this);
        f.text("name", "اسم التصنيف", c == null ? null : Json.str(c, "name"), "أكشن", Form.TEXT).required().max(64);
        f.text("name_en", "الاسم بالإنجليزية", c == null ? null : Json.str(c, "name_en"), "Action", Form.TEXT).max(64);
        f.text("slug", "المعرف في الروابط", c == null ? null : Json.str(c, "slug"), "action", Form.CODE).required().max(64)
                .pattern("^[a-z0-9]+(?:-[a-z0-9]+)*$", "حروف إنجليزية صغيرة وأرقام وشرطة فقط");
        f.select("icon", "الأيقونة", Labels.CATEGORY_ICONS, c == null ? null : Json.str(c, "icon"), true);
        f.color("color", "اللون", c == null ? "#8B7CFF" : Json.str(c, "color")).required();
        f.text("sort_order", "الترتيب", c == null ? null : String.valueOf(Json.num(c, "sort_order", 0)), "", Form.INT);
        final AlertDialog[] d = new AlertDialog[1];
        d[0] = Dialogs.custom(this, c == null ? "تصنيف جديد" : "تعديل التصنيف", f.view(), "حفظ", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                if (!f.validate()) {
                    return;
                }
                Api.Callback cb = new Api.Callback() {
                    @Override
                    public void done(Api.Result r) {
                        if (!alive()) {
                            return;
                        }
                        if (r.ok) {
                            d[0].dismiss();
                            toast("تم حفظ التصنيف");
                            reload();
                        } else {
                            String rest = r.error.fields == null ? r.error.getMessage() : f.showErrors(r.error.fields);
                            if (rest.length() > 0) {
                                toast(rest);
                            }
                        }
                    }
                };
                if (c == null) {
                    api.post("/admin/categories", f.values(), cb);
                } else {
                    api.put("/admin/categories/" + Json.num(c, "id", 0), f.values(), cb);
                }
            }
        });
        if (c != null) {
            f.addView(Ui.space(this, 8));
            f.addView(Ui.button(this, "حذف التصنيف", R.drawable.ic_trash, Ui.BTN_DANGER, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    Dialogs.confirm(CategoriesActivity.this, "حذف التصنيف", "سيُزال التصنيف «" + Json.str(c, "name", "") + "» من كل الألعاب (الألعاب نفسها لا تُحذف).",
                            "حذف", true, new Runnable() {
                                @Override
                                public void run() {
                                    d[0].dismiss();
                                    api.delete("/admin/categories/" + Json.num(c, "id", 0), new Api.Callback() {
                                        @Override
                                        public void done(Api.Result r) {
                                            if (!alive()) {
                                                return;
                                            }
                                            if (r.ok) {
                                                toast("حُذف التصنيف");
                                                reload();
                                            } else {
                                                fail(r);
                                            }
                                        }
                                    });
                                }
                            });
                }
            }));
        }
    }

    @Override
    protected String emptyTitle() {
        return "لا توجد تصنيفات";
    }

    @Override
    protected int emptyIcon() {
        return R.drawable.ic_tag;
    }
}
