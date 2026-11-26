package com.upsl.admin;

import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;

import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

/** Disk usage, space by file type, largest files, orphaned files and clean-up actions. */
public class StorageActivity extends Screen {
    @Override
    protected void build(Bundle saved) {
        setTitleText("التخزين");
        if (!require("storage.manage")) {
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

    private void load() {
        showLoading();
        api.get("/admin/storage", null, new Api.Callback() {
            @Override
            public void done(final Api.Result r) {
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
                api.get("/admin/storage/orphans", null, new Api.Callback() {
                    @Override
                    public void done(Api.Result o) {
                        if (alive()) {
                            render(r.obj(), o.ok ? Json.list(o.arr()) : null);
                        }
                    }
                });
            }
        });
    }

    private void render(JSONObject s, List<JSONObject> orphans) {
        LinearLayout col = page();
        LinearLayout disks = Ui.card(this);
        disks.addView(Ui.h2(this, "أقراص التخزين"));
        for (JSONObject d : Json.list(Json.arr(s, "disks"))) {
            long total = Json.lng(d, "total", 0);
            long free = Json.lng(d, "free", 0);
            float used = total > 0 ? (total - free) / (float) total : 0;
            disks.addView(Ui.meter(this, "«" + Json.str(d, "name", "?") + "»" + (Json.bool(d, "writable") ? "" : " — غير قابل للكتابة!"),
                    "متاح " + Fmt.bytes(free) + " من " + Fmt.bytes(total), used, used > 0.9f ? Ui.BAD : used > 0.75f ? Ui.WARN : Ui.OK));
            disks.addView(Ui.small(this, "ملفات المكتبة: " + Fmt.num(Json.lng(d, "library_files", 0)) + " ملف • " + Fmt.bytes(Json.lng(d, "library_bytes", 0))));
        }
        col.addView(disks);

        LinearLayout other = Ui.card(this);
        other.addView(Ui.h2(this, "مساحات أخرى"));
        other.addView(Ui.kv(this, "الصور والوسائط", Fmt.bytes(Json.lng(s, "media_bytes", 0))));
        other.addView(Ui.kv(this, "أجزاء رفع مؤقتة", Fmt.bytes(Json.lng(s, "chunks_bytes", 0))));
        other.addView(Ui.kv(this, "النسخ الاحتياطية", Fmt.bytes(Json.lng(s, "backups_bytes", 0))));
        other.addView(Ui.kv(this, "السجلات", Fmt.bytes(Json.lng(s, "logs_bytes", 0))));
        other.addView(Ui.kv(this, "ملفات مؤقتة", Fmt.bytes(Json.lng(s, "tmp_bytes", 0))));
        col.addView(other);

        List<JSONObject> types = Json.list(Json.arr(s, "by_type"));
        if (!types.isEmpty()) {
            LinearLayout bt = Ui.card(this);
            bt.addView(Ui.h2(this, "حسب نوع الملف"));
            long max = 1;
            for (JSONObject t : types) {
                max = Math.max(max, Json.lng(t, "bytes", 0));
            }
            for (JSONObject t : types) {
                bt.addView(Ui.meter(this, Labels.of(Labels.FILE_TYPES, Json.str(t, "file_type")),
                        Fmt.num(Json.lng(t, "files", 0)) + " ملف • " + Fmt.bytes(Json.lng(t, "bytes", 0)), Json.lng(t, "bytes", 0) / (float) max, Ui.ACCENT2));
            }
            col.addView(bt);
        }

        List<JSONObject> largest = Json.list(Json.arr(s, "largest"));
        if (!largest.isEmpty()) {
            col.addView(Ui.h2(this, "أكبر الملفات"));
            for (final JSONObject f : largest) {
                LinearLayout r = Ui.row(this, Ui.iconPlate(this, R.drawable.ic_file, Ui.ACCENT3, 36), Json.str(f, "original_name", ""),
                        Json.str(f, "game_title", "") + " • " + Fmt.bytes(Json.lng(f, "size_bytes", 0)), null);
                r.setOnClickListener(new View.OnClickListener() {
                    @Override
                    public void onClick(View v) {
                        open(GameActivity.class, "id", Json.num(f, "game_id", 0), "tab", 2);
                    }
                });
                col.addView(r);
            }
        }

        LinearLayout orph = Ui.card(this);
        orph.addView(Ui.h2(this, "ملفات يتيمة"));
        if (orphans == null) {
            orph.addView(Ui.muted(this, "تعذر فحص الملفات اليتيمة."));
        } else if (orphans.isEmpty()) {
            orph.addView(Ui.muted(this, "لا توجد ملفات على الأقراص غير مسجلة في المكتبة ✓"));
        } else {
            long total = 0;
            for (JSONObject o : orphans) {
                total += Json.lng(o, "size", 0);
            }
            orph.addView(Ui.muted(this, Fmt.count(orphans.size(), "ملف واحد", "ملفان", "ملفات") + " (" + Fmt.bytes(total)
                    + ") موجودة على القرص دون أن تتبع أي لعبة — مثل نسخ يدوية أو بقايا عملية متوقفة."));
            for (int i = 0; i < Math.min(orphans.size(), 25); i++) {
                JSONObject o = orphans.get(i);
                orph.addView(Ui.mono(this, Json.str(o, "disk", "") + ":/" + Json.str(o, "path", "") + "  (" + Json.str(o, "size_human", "") + ")", 11, Ui.MIST));
            }
            if (orphans.size() > 25) {
                orph.addView(Ui.small(this, "و" + (orphans.size() - 25) + " غيرها…"));
            }
            orph.addView(Ui.space(this, 10));
            orph.addView(Ui.button(this, "حذف الملفات اليتيمة", R.drawable.ic_trash, Ui.BTN_DANGER, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    Dialogs.confirm(StorageActivity.this, "حذف الملفات اليتيمة", "ستُحذف هذه الملفات من القرص نهائياً. تأكد أنها ليست نسخاً تحتاجها.",
                            "حذف", true, new Runnable() {
                                @Override
                                public void run() {
                                    action("orphans", "جارٍ الحذف…");
                                }
                            });
                }
            }), Ui.fill());
        }
        col.addView(orph);

        LinearLayout tools = Ui.card(this);
        tools.addView(Ui.h2(this, "أدوات الصيانة"));
        List<View> b = new ArrayList<View>();
        b.add(Ui.button(this, "تنظيف عمليات الرفع", R.drawable.ic_wand, Ui.BTN_SECONDARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                Dialogs.confirm(StorageActivity.this, "تنظيف عمليات الرفع", "تُحذف أجزاء عمليات الرفع المتوقفة أو الملغاة أو الفاشلة من الخادم. العمليات الجارية الآن لا تتأثر.",
                        "تنظيف", false, new Runnable() {
                            @Override
                            public void run() {
                                action("cleanup", "جارٍ التنظيف…");
                            }
                        });
            }
        }));
        b.add(Ui.button(this, "مسح الكاش", R.drawable.ic_refresh, Ui.BTN_SECONDARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                action("cache", "جارٍ المسح…");
            }
        }));
        tools.addView(Ui.grid(this, b, 2));
        col.addView(tools);
    }

    private void action(String name, String message) {
        busy(message);
        api.post("/admin/storage/" + name, new JSONObject(), new Api.Callback() {
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
                JSONObject d = r.obj();
                if (d.has("cleaned_uploads")) {
                    toast("نُظفت " + Fmt.count(Json.num(d, "cleaned_uploads", 0), "عملية واحدة", "عمليتان", "عمليات"));
                } else if (d.has("cleared")) {
                    toast("مُسح الكاش (" + Json.str(d, "driver", "") + ")");
                } else {
                    toast("تم: " + d.toString());
                }
                load();
            }
        });
    }
}
