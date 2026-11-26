package com.upsl.admin;

import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;

import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

/** Create or edit a platform, including its logo and cover image. */
public class PlatformFormActivity extends Screen {
    private JSONObject platform;
    private int id;
    private Form form;

    @Override
    protected void build(Bundle saved) {
        platform = extraJson(getIntent(), "json");
        if (platform == null) {
            platform = new JSONObject();
        }
        id = Json.num(platform, "id", 0);
        setTitleText(id > 0 ? Json.str(platform, "name", "جهاز") : "جهاز جديد");
        if (!require("platforms.manage")) {
            return;
        }
        render();
    }

    private void render() {
        LinearLayout col = page();
        if (id > 0) {
            LinearLayout media = Ui.card(this);
            media.addView(Ui.h2(this, "الشعار والصورة"));
            LinearLayout row = Ui.hbox(this);
            row.addView(Ui.remote(this, ImageLoader.pick(Json.obj(platform, "logo"), "md", "md_png"), 84, 84, 14));
            row.addView(Ui.hspace(this, 12));
            row.addView(Ui.remote(this, ImageLoader.pick(Json.obj(platform, "image"), "sm", "sm_jpg"), 0, 84, 14), Ui.weight(1));
            media.addView(row);
            media.addView(Ui.space(this, 10));
            List<View> b = new ArrayList<View>();
            b.add(Ui.button(this, "تغيير الشعار", R.drawable.ic_image, Ui.BTN_SECONDARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    upload("logo");
                }
            }));
            b.add(Ui.button(this, "تغيير الصورة", R.drawable.ic_image, Ui.BTN_SECONDARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    upload("image");
                }
            }));
            media.addView(Ui.grid(this, b, 2));
            media.addView(Ui.small(this, "الشعار: PNG شفاف مربع يفضّل. الصورة: 16:9."));
            col.addView(media);
        }
        LinearLayout card = Ui.card(this);
        form = new Form(this);
        form.text("name", "اسم الجهاز", Json.str(platform, "name"), "PlayStation 4", Form.TEXT).required().max(64);
        form.text("short_name", "الاسم المختصر", Json.str(platform, "short_name"), "PS4", Form.TEXT).required().max(16);
        form.text("slug", "المعرف في الروابط", Json.str(platform, "slug"), "ps4", Form.CODE).required().max(32)
                .pattern("^[a-z0-9]+(?:-[a-z0-9]+)*$", "حروف إنجليزية صغيرة وأرقام وشرطة فقط")
                .help("يظهر في روابط الموقع؛ تغييره يغيّر روابط صفحات هذا الجهاز.");
        form.color("accent_color", "لون الجهاز", Json.str(platform, "color", "#5AA9FF")).required();
        form.text("manufacturer", "الشركة المصنعة", Json.str(platform, "manufacturer", id == 0 ? "Sony Interactive Entertainment" : null)).max(64);
        form.text("release_year", "سنة الإصدار", Json.num(platform, "release_year", 0) > 0 ? String.valueOf(Json.num(platform, "release_year", 0)) : null, "2013", Form.INT)
                .range(1970, 2100);
        form.text("generation", "الجيل", Json.num(platform, "generation", 0) > 0 ? String.valueOf(Json.num(platform, "generation", 0)) : null, "8", Form.INT).range(1, 20);
        form.text("supported_formats", "الصيغ المدعومة", Json.str(platform, "supported_formats"), "PKG", Form.TEXT).max(255);
        form.text("description", "الوصف", Json.str(platform, "description"), "", Form.MULTILINE).max(5000);
        form.text("compatibility_notes", "ملاحظات التوافق", Json.str(platform, "compatibility_notes"), "", Form.MULTILINE).max(5000);
        form.text("install_notes", "ملاحظات التثبيت", Json.str(platform, "install_notes"), "", Form.MULTILINE).max(5000)
                .help("اكتب هنا متطلبات التثبيت الرسمية أو القيود؛ تظهر في صفحات ألعاب هذا الجهاز.");
        form.text("sort_order", "الترتيب", id > 0 ? String.valueOf(Json.num(platform, "sort_order", 0)) : null, "1", Form.INT);
        form.toggle("is_active", "ظاهر في الموقع", id == 0 || Json.bool(platform, "is_active"), null);
        card.addView(form.view());
        col.addView(card);
        col.addView(Ui.button(this, id > 0 ? "حفظ" : "إضافة الجهاز", R.drawable.ic_save, Ui.BTN_PRIMARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                save();
            }
        }), Ui.fill());
    }

    private void save() {
        hideKeyboard();
        if (form.str("slug") != null && form.str("slug").length() == 0 && form.str("short_name") != null) {
            form.set("slug", form.str("short_name").toLowerCase(Locale.US).replaceAll("[^a-z0-9]+", "-").replaceAll("(^-|-$)", ""));
        }
        if (!form.validate()) {
            return;
        }
        busy("جارٍ الحفظ…");
        Api.Callback cb = new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                busy(null);
                if (!alive()) {
                    return;
                }
                if (r.ok) {
                    markDirty("platforms", "games");
                    toast("تم الحفظ");
                    if (id == 0) {
                        platform = r.obj();
                        id = Json.num(platform, "id", 0);
                        setTitleText(Json.str(platform, "name", ""));
                        render();
                    } else {
                        finish();
                    }
                    return;
                }
                String rest = r.error.fields == null ? r.error.getMessage() : form.showErrors(r.error.fields);
                if (rest.length() > 0) {
                    toast(rest);
                }
            }
        };
        if (id > 0) {
            api.put("/admin/platforms/" + id, form.values(), cb);
        } else {
            api.post("/admin/platforms", form.values(), cb);
        }
    }

    private void upload(final String kind) {
        pickImage(new Ui.Callback<Uri>() {
            @Override
            public void done(Uri uri) {
                busy("جارٍ رفع الصورة…");
                api.upload("/admin/platforms/" + id + "/" + kind, uri, "file", null, null, new Api.Callback() {
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
                        toast("تم تحديث الصورة");
                        markDirty("platforms");
                        JSONObject urls = r.obj();
                        Json.put(platform, kind, urls);
                        render();
                    }
                });
            }
        });
    }
}
