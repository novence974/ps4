package com.upsl.admin;

import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;

import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

/** Bulk import of games from CSV or JSON: preview with validation and duplicate detection, then commit. */
public class ImportGamesActivity extends Screen {
    private String token;
    private JSONObject preview;
    private int onDuplicate;
    private static final String[][] DUP = {{"skip", "تخطي المكرر"}, {"update", "تحديث اللعبة الموجودة"}, {"create", "إضافة نسخة جديدة"}};

    @Override
    protected void build(Bundle saved) {
        setTitleText("استيراد الألعاب");
        if (!require("games.import")) {
            return;
        }
        intro();
    }

    private void intro() {
        LinearLayout col = page();
        LinearLayout c = Ui.card(this);
        c.addView(Ui.h2(this, "1. اختر ملف CSV أو JSON"));
        c.addView(Ui.muted(this, "سطر العناوين في CSV (أو مفاتيح JSON):"));
        c.addView(Ui.space(this, 6));
        c.addView(Ui.mono(this, "title,platform,region,release_year,developer,publisher,\nage_rating,size,languages,version,edition,rating,\nproduct_code,trailer_url,categories,description,\npublished,featured", 11.5f, Ui.ACCENT3));
        c.addView(Ui.space(this, 8));
        c.addView(Ui.small(this, "platform: المعرف أو الاسم المختصر (ps4 أو PS4). categories: أسماء أو معرفات مفصولة بـ | . languages: رموز مثل ar|en. size: مثل 45 GB. الحد الأقصى 30MB."));
        c.addView(Ui.space(this, 12));
        c.addView(Ui.button(this, "اختيار الملف", R.drawable.ic_file_sheet, Ui.BTN_PRIMARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                pickFile(new String[]{"text/csv", "text/comma-separated-values", "application/json", "text/plain", "application/octet-stream"}, new Ui.Callback<Uri>() {
                    @Override
                    public void done(Uri uri) {
                        upload(uri);
                    }
                });
            }
        }), Ui.fill());
        col.addView(c);
        LinearLayout n = Ui.card(this);
        n.addView(Ui.h2(this, "كيف يعمل"));
        n.addView(Ui.muted(this, "• المعاينة لا تغيّر شيئاً: تعرض كل سطر وحالته (جديد، مكرر، خطأ).\n• تختار ماذا يحدث للمكرر ثم تؤكد.\n• الأسطر التي فيها أخطاء لا تُستورد أبداً."));
        col.addView(n);
    }

    private void upload(Uri uri) {
        busy("جارٍ رفع الملف وتحليله…");
        api.upload("/admin/import", uri, "file", Api.q("mode", "preview"), null, new Api.Callback() {
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
                preview = r.obj();
                token = Json.str(preview, "token");
                showPreview();
            }
        });
    }

    private void showPreview() {
        LinearLayout col = page();
        JSONObject s = Json.obj(preview, "summary");
        LinearLayout sum = Ui.card(this);
        sum.addView(Ui.h2(this, "2. نتيجة المعاينة"));
        List<View> tiles = new ArrayList<View>();
        tiles.add(Ui.stat(this, R.drawable.ic_list, Ui.ACCENT2, String.valueOf(Json.num(s, "total", 0)), "سطر", null));
        tiles.add(Ui.stat(this, R.drawable.ic_sparkles, Ui.OK, String.valueOf(Json.num(s, "new", 0)), "جديد", null));
        tiles.add(Ui.stat(this, R.drawable.ic_compare, Ui.WARN, String.valueOf(Json.num(s, "duplicate", 0) + Json.num(s, "duplicate_in_file", 0)), "مكرر", null));
        tiles.add(Ui.stat(this, R.drawable.ic_err, Ui.BAD, String.valueOf(Json.num(s, "invalid", 0)), "فيه أخطاء", null));
        sum.addView(Ui.grid(this, tiles, 2));
        col.addView(sum);

        LinearLayout opts = Ui.card(this);
        opts.addView(Ui.h2(this, "3. ماذا نفعل بالمكرر؟"));
        List<String> labels = new ArrayList<String>();
        for (String[] d : DUP) {
            labels.add(d[1]);
        }
        opts.addView(Ui.pillRow(this, labels, onDuplicate, new Ui.IntCallback() {
            @Override
            public void done(int value) {
                onDuplicate = value;
                showPreview();
            }
        }));
        opts.addView(Ui.small(this, onDuplicate == 0 ? "الألعاب الموجودة لا تُلمس." : onDuplicate == 1
                ? "تُحدّث بيانات اللعبة الموجودة (نفس الاسم والجهاز والمنطقة) من الملف." : "تُضاف كلعبة منفصلة حتى لو تشابهت."));
        opts.addView(Ui.space(this, 10));
        opts.addView(Ui.button(this, "تأكيد الاستيراد", R.drawable.ic_check, Ui.BTN_PRIMARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                commit();
            }
        }), Ui.fill());
        opts.addView(Ui.button(this, "اختيار ملف آخر", 0, Ui.BTN_GHOST, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                intro();
            }
        }), Ui.fill());
        col.addView(opts);

        col.addView(Ui.h2(this, "الأسطر"));
        List<JSONObject> rows = Json.list(Json.arr(preview, "rows"));
        int shown = 0;
        for (JSONObject row : rows) {
            if (shown++ >= 300) {
                col.addView(Ui.small(this, "تُعرض أول 300 سطر فقط."));
                break;
            }
            String st = Json.str(row, "status", "");
            int color = "new".equals(st) ? Ui.OK : "invalid".equals(st) ? Ui.BAD : Ui.WARN;
            String label = "new".equals(st) ? "جديد" : "invalid".equals(st) ? "خطأ" : "duplicate_in_file".equals(st) ? "مكرر في الملف" : "موجود";
            StringBuilder sub = new StringBuilder("السطر " + Json.num(row, "line", 0) + " • " + Json.str(row, "platform", ""));
            JSONObject errs = Json.obj(row, "errors");
            for (String k : Json.keys(errs)) {
                sub.append("\n⚠ ").append(Json.str(errs, k));
            }
            JSONObject dup = Json.obj(row, "duplicate_of");
            if (dup != null) {
                sub.append("\nيطابق: ").append(Json.str(dup, "title", "")).append(" (#").append(Json.num(dup, "id", 0)).append(")");
            }
            col.addView(Ui.row(this, null, Json.str(row, "title", "(بدون اسم)"), sub.toString(), Ui.chip(this, label, color)));
        }
    }

    private void commit() {
        busy("جارٍ الاستيراد…");
        api.post("/admin/import", Json.of("token", token, "mode", "commit", "on_duplicate", DUP[onDuplicate][0]), new Api.Callback() {
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
                markDirty("games");
                LinearLayout col = page();
                LinearLayout c = Ui.card(ImportGamesActivity.this);
                c.addView(Ui.h2(ImportGamesActivity.this, "اكتمل الاستيراد"));
                List<View> tiles = new ArrayList<View>();
                tiles.add(Ui.stat(ImportGamesActivity.this, R.drawable.ic_plus, Ui.OK, String.valueOf(Json.num(d, "created", 0)), "أضيفت", null));
                tiles.add(Ui.stat(ImportGamesActivity.this, R.drawable.ic_refresh, Ui.ACCENT2, String.valueOf(Json.num(d, "updated", 0)), "حُدّثت", null));
                tiles.add(Ui.stat(ImportGamesActivity.this, R.drawable.ic_undo, Ui.MIST, String.valueOf(Json.num(d, "skipped", 0)), "تُخطيت", null));
                tiles.add(Ui.stat(ImportGamesActivity.this, R.drawable.ic_err, Ui.BAD, String.valueOf(Json.num(d, "failed", 0)), "فشلت", null));
                c.addView(Ui.grid(ImportGamesActivity.this, tiles, 2));
                for (String e : Json.strings(Json.arr(d, "errors"))) {
                    c.addView(Ui.text(ImportGamesActivity.this, "⚠ " + e, 12.5f, Ui.WARN, false));
                }
                c.addView(Ui.space(ImportGamesActivity.this, 10));
                c.addView(Ui.button(ImportGamesActivity.this, "عرض الألعاب", R.drawable.ic_gamepad, Ui.BTN_PRIMARY, new View.OnClickListener() {
                    @Override
                    public void onClick(View v) {
                        open(GamesActivity.class);
                        finish();
                    }
                }), Ui.fill());
                col.addView(c);
            }
        });
    }
}
