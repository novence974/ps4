package com.upsl.admin;

import android.app.AlertDialog;
import android.net.Uri;
import android.os.Bundle;
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.widget.HorizontalScrollView;
import android.widget.LinearLayout;
import android.widget.TextView;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.List;

/** One game: overview, media (cover, banner, screenshots, trailer), hosted files, versions, links and compatibility. */
public class GameActivity extends Screen implements Transfers.Listener {
    private int id;
    private JSONObject game;
    private int tab;
    private static final List<String> TABS = Arrays.asList("نظرة عامة", "الوسائط", "الملفات", "الإصدارات", "الروابط", "التوافق");

    @Override
    protected void build(Bundle saved) {
        id = intExtra("id");
        tab = saved != null ? saved.getInt("tab", 0) : getIntent().getIntExtra("tab", 0);
        setTitleText("لعبة");
        addAction(R.drawable.ic_refresh, "تحديث", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                load();
            }
        });
        load();
    }

    @Override
    protected void onSaveInstanceState(Bundle out) {
        super.onSaveInstanceState(out);
        out.putInt("tab", tab);
    }

    @Override
    protected void onResume() {
        super.onResume();
        Transfers.get(this).addListener(this);
        if (game != null && consumeDirty("game:" + id)) {
            load();
        }
    }

    @Override
    protected void onPause() {
        Transfers.get(this).removeListener(this);
        super.onPause();
    }

    @Override
    public void changed(Transfers.Task t) {
        if (Transfers.DONE.equals(t.status) && Json.num(t.fields, "game_id", 0) == id && alive()) {
            load();
        }
    }

    private void load() {
        if (game == null) {
            showLoading();
        }
        api.get("/admin/games/" + id, null, new Api.Callback() {
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
                game = r.obj();
                setTitleText(Json.str(game, "title", "لعبة"));
                render();
            }
        });
    }

    private void changedOnServer() {
        markDirty("games");
        load();
    }

    private void render() {
        LinearLayout col = page();
        HorizontalScrollView tabs = Ui.pillRow(this, TABS, tab, new Ui.IntCallback() {
            @Override
            public void done(int value) {
                tab = value;
                render();
            }
        });
        col.addView(tabs);
        switch (tab) {
            case 1:
                media(col);
                break;
            case 2:
                files(col);
                break;
            case 3:
                versions(col);
                break;
            case 4:
                links(col);
                break;
            case 5:
                compat(col);
                break;
            default:
                overview(col);
        }
    }

    // ------------------------------------------------------------------ overview

    private void overview(LinearLayout col) {
        JSONObject banner = Json.obj(game, "banner");
        if (banner != null) {
            View b = Ui.remote(this, ImageLoader.pick(banner, "sm", "sm_jpg"), 0, 150, 18);
            LinearLayout.LayoutParams p = (LinearLayout.LayoutParams) b.getLayoutParams();
            p.bottomMargin = Ui.dp(this, 12);
            col.addView(b);
        }
        LinearLayout head = Ui.card(this);
        LinearLayout top = Ui.hbox(this);
        top.setGravity(Gravity.TOP);
        top.addView(Ui.remote(this, ImageLoader.pick(Json.obj(game, "cover"), "sm", "sm_jpg"), 86, 120, 12));
        top.addView(Ui.hspace(this, 14));
        LinearLayout info = Ui.vbox(this);
        info.addView(Ui.text(this, Json.str(game, "title", ""), 18, Ui.INK, true));
        JSONObject plat = Json.obj(game, "platform");
        info.addView(Ui.muted(this, Json.str(plat, "name", "") + (Json.str(game, "edition") != null ? " — " + Json.str(game, "edition") : "")));
        info.addView(Ui.space(this, 8));
        LinearLayout chips = Ui.hbox(this);
        boolean pub = Json.bool(game, "is_published");
        chips.addView(Ui.chip(this, pub ? "منشورة" : "مسودة", pub ? Ui.OK : Ui.WARN));
        chips.addView(Ui.hspace(this, 6));
        if (Json.bool(game, "is_featured")) {
            chips.addView(Ui.chip(this, "مميزة", Ui.ACCENT));
            chips.addView(Ui.hspace(this, 6));
        }
        String fs = Json.str(game, "files_status", "unavailable");
        chips.addView(Ui.chip(this, Labels.of(Labels.FILES_STATUS, fs), "available".equals(fs) ? Ui.ACCENT3 : Ui.MIST));
        info.addView(chips);
        info.addView(Ui.space(this, 8));
        info.addView(Ui.small(this, Fmt.num(Json.lng(game, "downloads", 0)) + " تنزيل • " + Fmt.num(Json.lng(game, "views", 0)) + " مشاهدة • "
                + Fmt.num(Json.lng(game, "favorites", 0)) + " مفضلة"));
        top.addView(info, Ui.weight(1));
        head.addView(top);
        head.addView(Ui.space(this, 14));

        List<View> buttons = new ArrayList<View>();
        if (session.can("games.edit")) {
            buttons.add(Ui.button(this, "تعديل البيانات", R.drawable.ic_edit, Ui.BTN_PRIMARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    open(GameFormActivity.class, "id", id);
                }
            }));
        }
        if (session.can("games.publish")) {
            buttons.add(Ui.button(this, pub ? "إخفاء" : "نشر", pub ? R.drawable.ic_eye_off : R.drawable.ic_eye, Ui.BTN_SECONDARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    publish(!Json.bool(game, "is_published"));
                }
            }));
            final boolean featured = Json.bool(game, "is_featured");
            buttons.add(Ui.button(this, featured ? "إلغاء التمييز" : "تمييز", R.drawable.ic_star, Ui.BTN_SECONDARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    bulk(featured ? "unfeature" : "feature");
                }
            }));
        }
        buttons.add(Ui.button(this, "فتح في الموقع", R.drawable.ic_external, Ui.BTN_SECONDARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                openUrl(Json.str(game, "url", session.server()));
            }
        }));
        head.addView(Ui.grid(this, buttons, 2));
        col.addView(head);

        LinearLayout details = Ui.card(this);
        details.addView(Ui.h2(this, "البيانات"));
        details.addView(Ui.kv(this, "رمز المنتج", Json.str(game, "product_code")));
        details.addView(Ui.kv(this, "المنطقة", Labels.of(Labels.REGIONS, Json.str(game, "region"))));
        details.addView(Ui.kv(this, "سنة الإصدار", Json.num(game, "release_year", 0) > 0 ? String.valueOf(Json.num(game, "release_year", 0)) : null));
        details.addView(Ui.kv(this, "المطور", Json.str(game, "developer")));
        details.addView(Ui.kv(this, "الناشر", Json.str(game, "publisher")));
        details.addView(Ui.kv(this, "التصنيف العمري", Json.str(game, "age_rating")));
        details.addView(Ui.kv(this, "الحجم", Json.str(game, "size_human")));
        details.addView(Ui.kv(this, "الإصدار", Json.str(game, "version")));
        details.addView(Ui.kv(this, "التقييم", Json.str(game, "rating")));
        details.addView(Ui.kv(this, "اللغات", langs(Json.arr(game, "languages"))));
        details.addView(Ui.kv(this, "التصنيفات", names(Json.arr(game, "categories"))));
        details.addView(Ui.kv(this, "أضيفت", Fmt.date(Json.str(game, "created_at"))));
        details.addView(Ui.kv(this, "آخر تعديل", Fmt.date(Json.str(game, "updated_at"))));
        details.addView(Ui.kv(this, "رقم اللعبة", "#" + id));
        col.addView(details);

        if (Json.str(game, "description") != null) {
            LinearLayout d = Ui.card(this);
            d.addView(Ui.h2(this, "الوصف"));
            d.addView(Ui.body(this, Json.str(game, "description")));
            col.addView(d);
        }
        if (Json.str(game, "compatibility_notes") != null) {
            LinearLayout d = Ui.card(this);
            d.addView(Ui.h2(this, "ملاحظات التوافق"));
            d.addView(Ui.body(this, Json.str(game, "compatibility_notes")));
            col.addView(d);
        }
        if (session.can("games.delete")) {
            col.addView(Ui.space(this, 6));
            col.addView(Ui.button(this, "حذف اللعبة نهائياً", R.drawable.ic_trash, Ui.BTN_DANGER, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    Dialogs.confirm(GameActivity.this, "حذف اللعبة", "ستُحذف «" + Json.str(game, "title", "") + "» مع صورها وملفاتها المستضافة وإصداراتها وروابطها. لا يمكن التراجع.",
                            "حذف نهائي", true, new Runnable() {
                                @Override
                                public void run() {
                                    delete("/admin/games/" + id, new Runnable() {
                                        @Override
                                        public void run() {
                                            markDirty("games");
                                            toast("حُذفت اللعبة");
                                            finish();
                                        }
                                    });
                                }
                            });
                }
            }), Ui.fill());
        }
    }

    private String langs(JSONArray codes) {
        StringBuilder sb = new StringBuilder();
        for (String c : Json.strings(codes)) {
            if (sb.length() > 0) {
                sb.append("، ");
            }
            sb.append(Labels.of(Labels.LANGUAGES, c));
        }
        return sb.toString();
    }

    private String names(JSONArray arr) {
        StringBuilder sb = new StringBuilder();
        for (JSONObject o : Json.list(arr)) {
            if (sb.length() > 0) {
                sb.append("، ");
            }
            sb.append(Json.str(o, "name", ""));
        }
        return sb.toString();
    }

    private void publish(boolean on) {
        busy(on ? "جارٍ النشر…" : "جارٍ الإخفاء…");
        api.post("/admin/games/" + id + "/publish", Json.of("published", on), new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                busy(null);
                if (r.ok) {
                    changedOnServer();
                } else {
                    fail(r);
                }
            }
        });
    }

    private void bulk(String action) {
        JSONArray ids = new JSONArray();
        ids.put(id);
        busy("جارٍ الحفظ…");
        api.post("/admin/games/bulk", Json.of("ids", ids, "action", action), new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                busy(null);
                if (r.ok) {
                    changedOnServer();
                } else {
                    fail(r);
                }
            }
        });
    }

    private void delete(String path, final Runnable after) {
        busy("جارٍ الحذف…");
        api.delete(path, new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                busy(null);
                if (!alive()) {
                    return;
                }
                if (r.ok) {
                    after.run();
                } else {
                    fail(r);
                }
            }
        });
    }

    private Runnable reloadAfter() {
        return new Runnable() {
            @Override
            public void run() {
                changedOnServer();
            }
        };
    }

    // ------------------------------------------------------------------ media

    private void media(LinearLayout col) {
        boolean can = session.can("media.upload");
        LinearLayout images = Ui.card(this);
        images.addView(Ui.h2(this, "الغلاف والخلفية"));
        LinearLayout row = Ui.hbox(this);
        row.setGravity(Gravity.TOP);
        LinearLayout c1 = Ui.vbox(this);
        c1.addView(Ui.remote(this, ImageLoader.pick(Json.obj(game, "cover"), "md", "sm", "sm_jpg"), 110, 154, 12));
        c1.addView(Ui.small(this, "الغلاف 2:3"));
        row.addView(c1);
        row.addView(Ui.hspace(this, 12));
        LinearLayout c2 = Ui.vbox(this);
        c2.addView(Ui.remote(this, ImageLoader.pick(Json.obj(game, "banner"), "sm", "sm_jpg"), 0, 110, 12));
        c2.addView(Ui.small(this, "الخلفية 16:9"));
        row.addView(c2, Ui.weight(1));
        images.addView(row);
        if (can) {
            images.addView(Ui.space(this, 12));
            List<View> b = new ArrayList<View>();
            b.add(Ui.button(this, "تغيير الغلاف", R.drawable.ic_image, Ui.BTN_SECONDARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    uploadImage("cover", null);
                }
            }));
            b.add(Ui.button(this, "تغيير الخلفية", R.drawable.ic_image, Ui.BTN_SECONDARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    uploadImage("banner", null);
                }
            }));
            images.addView(Ui.grid(this, b, 2));
            images.addView(Ui.small(this, "JPG أو PNG أو WebP أو GIF. يحوّل الخادم الصور إلى WebP ويصنع منها أحجاماً مناسبة."));
        }
        col.addView(images);

        // screenshots
        final List<JSONObject> shots = Json.list(Json.arr(game, "screenshots"));
        LinearLayout sc = Ui.card(this);
        LinearLayout sh = Ui.hbox(this);
        sh.addView(Ui.text(this, "لقطات الشاشة (" + shots.size() + ")", 16, Ui.INK, true), Ui.weight(1));
        if (can) {
            sh.addView(Ui.button(this, "إضافة", R.drawable.ic_plus, Ui.BTN_SECONDARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    Dialogs.prompt(GameActivity.this, "وصف اللقطة (اختياري)", "مثال: القائمة الرئيسية", "", InputType.TYPE_CLASS_TEXT, new Ui.Callback<String>() {
                        @Override
                        public void done(String caption) {
                            uploadImage("screenshots", caption);
                        }
                    });
                }
            }));
        }
        sc.addView(sh);
        sc.addView(Ui.space(this, 8));
        if (shots.isEmpty()) {
            sc.addView(Ui.muted(this, "لا توجد لقطات بعد."));
        }
        for (int i = 0; i < shots.size(); i++) {
            final int index = i;
            final JSONObject s = shots.get(i);
            LinearLayout r = Ui.hbox(this);
            r.setPadding(0, Ui.dp(this, 4), 0, Ui.dp(this, 4));
            r.addView(Ui.remote(this, ImageLoader.pick(s, "sm", "sm_jpg"), 112, 63, 10));
            r.addView(Ui.hspace(this, 10));
            LinearLayout meta = Ui.vbox(this);
            meta.addView(Ui.single(Ui.body(this, Json.str(s, "caption", "بدون وصف"))));
            meta.addView(Ui.small(this, Json.num(s, "width", 0) + "×" + Json.num(s, "height", 0)));
            r.addView(meta, Ui.weight(1));
            if (can) {
                if (i > 0) {
                    r.addView(Ui.iconButton(this, R.drawable.ic_chev_right, "تقديم", new View.OnClickListener() {
                        @Override
                        public void onClick(View v) {
                            moveShot(shots, index, index - 1);
                        }
                    }));
                }
                r.addView(Ui.iconButton(this, R.drawable.ic_trash, "حذف", new View.OnClickListener() {
                    @Override
                    public void onClick(View v) {
                        Dialogs.confirm(GameActivity.this, "حذف اللقطة", "ستُحذف هذه اللقطة من اللعبة.", "حذف", true, new Runnable() {
                            @Override
                            public void run() {
                                delete("/admin/games/" + id + "/screenshots/" + Json.num(s, "id", 0), reloadAfter());
                            }
                        });
                    }
                }));
            }
            sc.addView(r);
        }
        if (can && shots.size() > 1) {
            sc.addView(Ui.small(this, "زر السهم يقدّم اللقطة خطوة في الترتيب."));
        }
        col.addView(sc);

        // trailer
        LinearLayout tr = Ui.card(this);
        tr.addView(Ui.h2(this, "الفيديو التعريفي"));
        JSONObject trailer = Json.obj(game, "trailer");
        if (trailer == null) {
            tr.addView(Ui.muted(this, "لا يوجد فيديو. أضف رابط يوتيوب/فيميو أو ارفع ملف MP4 أو WebM."));
        } else {
            tr.addView(Ui.kv(this, "النوع", "video".equals(Json.str(trailer, "type")) ? "ملف فيديو مرفوع" : Json.str(trailer, "type")));
            TextView u = Ui.mono(this, Json.str(trailer, "url", ""), 12, Ui.MIST);
            tr.addView(u);
        }
        if (session.can("games.edit") || can) {
            tr.addView(Ui.space(this, 10));
            List<View> b = new ArrayList<View>();
            if (session.can("games.edit")) {
                b.add(Ui.button(this, "رابط فيديو", R.drawable.ic_link, Ui.BTN_SECONDARY, new View.OnClickListener() {
                    @Override
                    public void onClick(View v) {
                        JSONObject t = Json.obj(game, "trailer");
                        Dialogs.prompt(GameActivity.this, "رابط الفيديو", "https://www.youtube.com/watch?v=…", t == null ? "" : Json.str(t, "url", ""),
                                InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI, new Ui.Callback<String>() {
                                    @Override
                                    public void done(String url) {
                                        saveField("trailer_url", url.length() == 0 ? null : url);
                                    }
                                });
                    }
                }));
            }
            if (can) {
                b.add(Ui.button(this, "رفع ملف فيديو", R.drawable.ic_film, Ui.BTN_SECONDARY, new View.OnClickListener() {
                    @Override
                    public void onClick(View v) {
                        pickFile(new String[]{"video/mp4", "video/webm"}, new Ui.Callback<Uri>() {
                            @Override
                            public void done(Uri uri) {
                                enqueue(uri, "trailer", Json.of("game_id", id));
                            }
                        });
                    }
                }));
            }
            if (trailer != null && session.can("games.edit")) {
                b.add(Ui.button(this, "إزالة الفيديو", R.drawable.ic_trash, Ui.BTN_DANGER, new View.OnClickListener() {
                    @Override
                    public void onClick(View v) {
                        Dialogs.confirm(GameActivity.this, "إزالة الفيديو", "سيُزال الفيديو من صفحة اللعبة (ويُحذف الملف إن كان مرفوعاً).", "إزالة", true, new Runnable() {
                            @Override
                            public void run() {
                                delete("/admin/games/" + id + "/trailer", reloadAfter());
                            }
                        });
                    }
                }));
            }
            tr.addView(Ui.grid(this, b, 2));
        }
        col.addView(tr);
    }

    private void moveShot(List<JSONObject> shots, int from, int to) {
        List<JSONObject> copy = new ArrayList<JSONObject>(shots);
        JSONObject s = copy.remove(from);
        copy.add(to, s);
        JSONArray ids = new JSONArray();
        for (JSONObject o : copy) {
            ids.put(Json.num(o, "id", 0));
        }
        busy("جارٍ حفظ الترتيب…");
        api.post("/admin/games/" + id + "/screenshots/reorder", Json.of("ids", ids), new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                busy(null);
                if (r.ok) {
                    changedOnServer();
                } else {
                    fail(r);
                }
            }
        });
    }

    private void uploadImage(final String kind, final String caption) {
        pickImage(new Ui.Callback<Uri>() {
            @Override
            public void done(Uri uri) {
                busy("جارٍ رفع الصورة…");
                api.upload("/admin/games/" + id + "/" + kind, uri, "file", caption == null || caption.length() == 0 ? null : Api.q("caption", caption), null,
                        new Api.Callback() {
                            @Override
                            public void done(Api.Result r) {
                                busy(null);
                                if (!alive()) {
                                    return;
                                }
                                if (r.ok) {
                                    toast("تم رفع الصورة");
                                    changedOnServer();
                                } else {
                                    fail(r);
                                }
                            }
                        });
            }
        });
    }

    private void saveField(String key, Object value) {
        busy("جارٍ الحفظ…");
        api.put("/admin/games/" + id, Json.of(key, value), new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                busy(null);
                if (r.ok) {
                    toast("تم الحفظ");
                    changedOnServer();
                } else {
                    fail(r);
                }
            }
        });
    }

    private void enqueue(Uri uri, String purpose, JSONObject fields) {
        askNotifications();
        Transfers.get(this).enqueue(this, uri, purpose, fields, Json.str(game, "title", ""));
        Dialogs.confirm(this, "بدأ الرفع", "يُرفع الملف على أجزاء مع التحقق من كل جزء، ويمكنك متابعة العمل أو إغلاق الشاشة. إذا انقطع الاتصال يكمل من حيث توقف.",
                "عرض عمليات الرفع", false, new Runnable() {
                    @Override
                    public void run() {
                        open(UploadsActivity.class);
                    }
                });
    }

    // ------------------------------------------------------------------ files

    private void files(LinearLayout col) {
        List<JSONObject> files = Json.list(Json.arr(game, "files"));
        if (session.can("files.upload")) {
            col.addView(Ui.button(this, "رفع ملف للعبة", R.drawable.ic_cloud_upload, Ui.BTN_PRIMARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    pickFile(new String[]{"*/*"}, new Ui.Callback<Uri>() {
                        @Override
                        public void done(Uri uri) {
                            fileMeta(uri);
                        }
                    });
                }
            }), Ui.fill());
            col.addView(Ui.small(this, "الامتدادات المسموحة يحددها المدير في «الإعدادات ← الأمان والرفع». يُحسب SHA-256 لكل جزء وللملف كاملاً."));
            col.addView(Ui.space(this, 12));
        }
        if (files.isEmpty()) {
            col.addView(Ui.state(this, R.drawable.ic_files, Ui.ACCENT, "لا توجد ملفات مستضافة", "ارفع ملف اللعبة أو تحديثاتها لتظهر في صفحة اللعبة.", null, null));
            return;
        }
        for (final JSONObject f : files) {
            col.addView(fileCard(f));
        }
    }

    private View fileCard(final JSONObject f) {
        LinearLayout c = Ui.card(this);
        LinearLayout top = Ui.hbox(this);
        top.addView(Ui.iconPlate(this, R.drawable.ic_file, Ui.ACCENT2, 38));
        top.addView(Ui.hspace(this, 10));
        LinearLayout t = Ui.vbox(this);
        TextView name = Ui.text(this, Json.str(f, "name", ""), 14.5f, Ui.INK, true);
        Ui.ltrText(name);
        t.addView(name);
        t.addView(Ui.small(this, Json.str(f, "type_label", "") + " • " + Json.str(f, "size_human", "") + " • " + Fmt.num(Json.lng(f, "downloads", 0)) + " تنزيل"));
        top.addView(t, Ui.weight(1));
        boolean active = Json.bool(f, "is_active");
        top.addView(Ui.chip(this, active ? "مفعّل" : "معطّل", active ? Ui.OK : Ui.MIST));
        c.addView(top);
        c.addView(Ui.space(this, 8));
        if (Json.str(f, "label") != null) {
            c.addView(Ui.kv(this, "الوصف", Json.str(f, "label")));
        }
        c.addView(Ui.kv(this, "صلاحية التنزيل", Labels.of(Labels.ACCESS, Json.str(f, "access_level"))));
        c.addView(Ui.kv(this, "المنطقة", Labels.of(Labels.REGIONS, Json.str(f, "region"))));
        c.addView(Ui.kv(this, "الإصدار", Json.str(f, "version")));
        c.addView(Ui.kv(this, "قرص التخزين", Json.str(f, "disk")));
        c.addView(Ui.kv(this, "آخر تحقق", Json.str(f, "verified_at") == null ? "لم يُتحقق بعد" : Fmt.date(Json.str(f, "verified_at"))));
        c.addView(Ui.kv(this, "SHA-256", ""));
        c.addView(Ui.mono(this, Json.str(f, "sha256", "—"), 11, Ui.MIST));
        if (session.can("files.manage")) {
            c.addView(Ui.space(this, 10));
            List<View> b = new ArrayList<View>();
            b.add(Ui.button(this, "تعديل", R.drawable.ic_edit, Ui.BTN_SECONDARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    editFile(f);
                }
            }));
            b.add(Ui.button(this, "تحقق من السلامة", R.drawable.ic_scan, Ui.BTN_SECONDARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    verify(f);
                }
            }));
            b.add(Ui.button(this, "حذف", R.drawable.ic_trash, Ui.BTN_DANGER, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    Dialogs.confirm(GameActivity.this, "حذف الملف", "سيُحذف «" + Json.str(f, "name", "") + "» من الخادم ولن يعود متاحاً للتنزيل.", "حذف", true, new Runnable() {
                        @Override
                        public void run() {
                            delete("/admin/files/" + Json.num(f, "id", 0), reloadAfter());
                        }
                    });
                }
            }));
            c.addView(Ui.grid(this, b, 3));
        }
        return c;
    }

    private String[][] versionOptions() {
        List<JSONObject> vs = Json.list(Json.arr(game, "versions"));
        String[][] out = new String[vs.size()][];
        for (int i = 0; i < vs.size(); i++) {
            JSONObject v = vs.get(i);
            out[i] = new String[]{String.valueOf(Json.num(v, "id", 0)), Json.str(v, "version", "") + (Json.str(v, "region") != null ? " (" + Json.str(v, "region") + ")" : "")};
        }
        return out;
    }

    private void fileMeta(final Uri uri) {
        final Form f = new Form(this);
        f.note("الملف: " + Api.displayName(this, uri) + " — " + Fmt.bytes(Api.fileSize(this, uri)));
        f.select("file_type", "نوع الملف", Labels.FILE_TYPES, "game", false);
        f.select("access_level", "من يمكنه التنزيل", Labels.ACCESS, "registered", false);
        f.text("label", "وصف قصير (اختياري)", null).max(190);
        f.select("region", "المنطقة", Labels.REGIONS, Json.str(game, "region"), true);
        f.text("version_label", "رقم الإصدار (اختياري)", Json.str(game, "version")).max(32);
        String[][] vo = versionOptions();
        if (vo.length > 0) {
            f.select("version_id", "ربط بإصدار مسجل", vo, null, true);
        }
        final AlertDialog[] d = new AlertDialog[1];
        d[0] = Dialogs.custom(this, "بيانات الملف", f.view(), "بدء الرفع", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                if (!f.validate()) {
                    return;
                }
                JSONObject fields = f.values();
                Json.put(fields, "game_id", id);
                d[0].dismiss();
                enqueue(uri, "game_file", fields);
            }
        });
    }

    private void editFile(final JSONObject file) {
        final Form f = new Form(this);
        f.select("file_type", "نوع الملف", Labels.FILE_TYPES, Json.str(file, "type"), false).required();
        f.select("access_level", "من يمكنه التنزيل", Labels.ACCESS, Json.str(file, "access_level"), false).required();
        f.text("label", "الوصف", Json.str(file, "label")).max(190);
        f.select("region", "المنطقة", Labels.REGIONS, Json.str(file, "region"), true);
        f.text("version_label", "رقم الإصدار", Json.str(file, "version")).max(32);
        String[][] vo = versionOptions();
        if (vo.length > 0) {
            f.select("version_id", "ربط بإصدار مسجل", vo, Json.str(file, "version_id"), true);
        }
        f.toggle("is_active", "متاح للتنزيل", Json.bool(file, "is_active"), "إيقافه يخفي الملف عن المستخدمين دون حذفه");
        final AlertDialog[] d = new AlertDialog[1];
        d[0] = Dialogs.custom(this, "تعديل الملف", f.view(), "حفظ", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                if (!f.validate()) {
                    return;
                }
                api.put("/admin/files/" + Json.num(file, "id", 0), f.values(), new Api.Callback() {
                    @Override
                    public void done(Api.Result r) {
                        if (!alive()) {
                            return;
                        }
                        if (r.ok) {
                            d[0].dismiss();
                            toast("تم حفظ الملف");
                            changedOnServer();
                        } else {
                            String rest = r.error.fields == null ? r.error.getMessage() : f.showErrors(r.error.fields);
                            if (rest.length() > 0) {
                                toast(rest);
                            }
                        }
                    }
                });
            }
        });
    }

    private void verify(JSONObject f) {
        busy("جارٍ حساب SHA-256 للملف على الخادم… قد يستغرق ذلك وقتاً للملفات الكبيرة");
        api.post("/admin/files/" + Json.num(f, "id", 0) + "/verify", new JSONObject(), new Api.Callback() {
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
                JSONObject v = r.obj();
                boolean ok = Json.bool(v, "ok");
                Dialogs.info(GameActivity.this, ok ? "الملف سليم ✓" : "الملف لا يطابق البصمة المسجلة",
                        (ok ? "بصمة الملف على القرص تطابق البصمة المسجلة.\n\n" : "قد يكون الملف تلف أو استُبدل على القرص. أعد رفعه.\n\n")
                                + "المحسوبة:\n" + Json.str(v, "sha256", "—") + "\n\nالمسجلة:\n" + Json.str(v, "expected", "—"));
                load();
            }
        });
    }

    // ------------------------------------------------------------------ versions

    private void versions(LinearLayout col) {
        List<JSONObject> vs = Json.list(Json.arr(game, "versions"));
        final boolean can = session.can("games.edit");
        if (can) {
            col.addView(Ui.button(this, "إضافة إصدار", R.drawable.ic_plus, Ui.BTN_PRIMARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    editVersion(null);
                }
            }), Ui.fill());
            col.addView(Ui.space(this, 12));
        }
        if (vs.isEmpty()) {
            col.addView(Ui.state(this, R.drawable.ic_layers, Ui.ACCENT, "لا توجد إصدارات مسجلة", "سجّل إصدارات اللعبة وتحديثاتها مع سجل التغييرات.", null, null));
        }
        for (final JSONObject v : vs) {
            LinearLayout trailing = Ui.vbox(this);
            if (Json.bool(v, "is_latest")) {
                trailing.addView(Ui.chip(this, "الأحدث", Ui.OK));
            }
            String sub = Labels.of(Labels.REGIONS, Json.str(v, "region")) + (Json.str(v, "release_date") != null ? " • " + Json.str(v, "release_date") : "")
                    + (Json.lng(v, "size_bytes", 0) > 0 ? " • " + Fmt.bytes(Json.lng(v, "size_bytes", 0)) : "")
                    + (Json.str(v, "changelog") != null ? "\n" + Json.str(v, "changelog") : "");
            LinearLayout r = Ui.row(this, Ui.iconPlate(this, R.drawable.ic_layers, Ui.ACCENT2, 38), "الإصدار " + Fmt.ltr(Json.str(v, "version", "")), sub, trailing);
            if (can) {
                r.setOnClickListener(new View.OnClickListener() {
                    @Override
                    public void onClick(View view) {
                        editVersion(v);
                    }
                });
            }
            col.addView(r);
        }
    }

    private void editVersion(final JSONObject v) {
        final Form f = new Form(this);
        f.text("version", "رقم الإصدار", v == null ? null : Json.str(v, "version"), "1.02", Form.CODE).required().max(32);
        f.select("region", "المنطقة", Labels.REGIONS, v == null ? Json.str(game, "region") : Json.str(v, "region"), true);
        f.text("release_date", "تاريخ الإصدار", v == null ? null : Json.str(v, "release_date"), "2026-11-25", Form.DATE)
                .pattern("^\\d{4}-\\d{2}-\\d{2}$", "اكتب التاريخ بصيغة YYYY-MM-DD");
        f.text("size", "الحجم", v == null || Json.lng(v, "size_bytes", 0) == 0 ? null : Fmt.bytes(Json.lng(v, "size_bytes", 0)).replace("\u200E", ""), "مثال: 1.2 GB", Form.TEXT).max(40);
        f.text("changelog", "سجل التغييرات", v == null ? null : Json.str(v, "changelog"), "", Form.MULTILINE).max(10000);
        f.toggle("is_latest", "أحدث إصدار", v == null || Json.bool(v, "is_latest"), "يظهر كإصدار حالي في صفحة اللعبة");
        if (v == null) {
            f.toggle("notify", "نشر تحديث وإشعار المهتمين", false, "ينشئ خبراً من نوع «إصدار/تصحيح» ويُشعر من أضاف اللعبة للمفضلة");
        }
        final AlertDialog[] d = new AlertDialog[1];
        d[0] = Dialogs.custom(this, v == null ? "إصدار جديد" : "تعديل الإصدار", f.view(), "حفظ", new View.OnClickListener() {
            @Override
            public void onClick(View view) {
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
                            toast("تم حفظ الإصدار");
                            changedOnServer();
                        } else {
                            String rest = r.error.fields == null ? r.error.getMessage() : f.showErrors(r.error.fields);
                            if (rest.length() > 0) {
                                toast(rest);
                            }
                        }
                    }
                };
                if (v == null) {
                    api.post("/admin/games/" + id + "/versions", f.values(), cb);
                } else {
                    api.put("/admin/games/" + id + "/versions/" + Json.num(v, "id", 0), f.values(), cb);
                }
            }
        });
        if (v != null) {
            deleteButtonIn(f, "حذف الإصدار", "/admin/games/" + id + "/versions/" + Json.num(v, "id", 0), d[0]);
        }
    }

    /** Adds a red delete button at the bottom of a dialog form. */
    private void deleteButtonIn(Form f, final String title, final String path, final AlertDialog d) {
        f.addView(Ui.space(this, 8));
        f.addView(Ui.button(this, title, R.drawable.ic_trash, Ui.BTN_DANGER, new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                Dialogs.confirm(GameActivity.this, title, "هل أنت متأكد؟ لا يمكن التراجع.", "حذف", true, new Runnable() {
                    @Override
                    public void run() {
                        d.dismiss();
                        delete(path, reloadAfter());
                    }
                });
            }
        }));
    }

    // ------------------------------------------------------------------ links

    private void links(LinearLayout col) {
        List<JSONObject> ls = Json.list(Json.arr(game, "links"));
        final boolean can = session.can("links.manage");
        col.addView(Ui.small(this, "روابط رسمية أو مصرح بها فقط (المتجر الرسمي، موقع الناشر، مصادر مرخّصة، صفحات معلومات)."));
        col.addView(Ui.space(this, 8));
        if (can) {
            col.addView(Ui.button(this, "إضافة رابط", R.drawable.ic_plus, Ui.BTN_PRIMARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    editLink(null);
                }
            }), Ui.fill());
            col.addView(Ui.space(this, 12));
        }
        if (ls.isEmpty()) {
            col.addView(Ui.state(this, R.drawable.ic_link, Ui.ACCENT, "لا توجد روابط", "", null, null));
        }
        for (final JSONObject l : ls) {
            boolean active = Json.bool(l, "is_active");
            LinearLayout r = Ui.row(this, Ui.iconPlate(this, R.drawable.ic_link, Ui.ACCENT3, 38), Json.str(l, "title", ""),
                    Json.str(l, "type_label", "") + (Json.str(l, "region") != null ? " • " + Json.str(l, "region") : "") + "\n" + Json.str(l, "url", ""),
                    Ui.chip(this, active ? "ظاهر" : "مخفي", active ? Ui.OK : Ui.MIST));
            r.setOnClickListener(new View.OnClickListener() {
                @Override
                public void onClick(View view) {
                    if (can) {
                        editLink(l);
                    } else {
                        openUrl(Json.str(l, "url", ""));
                    }
                }
            });
            col.addView(r);
        }
    }

    private void editLink(final JSONObject l) {
        final Form f = new Form(this);
        f.text("title", "عنوان الرابط", l == null ? null : Json.str(l, "title")).required().max(190);
        f.text("url", "الرابط", l == null ? null : Json.str(l, "url"), "https://", Form.URL).required().max(1000);
        f.select("link_type", "نوع الرابط", Labels.LINK_TYPES, l == null ? "official_store" : Json.str(l, "type"), false).required();
        f.select("region", "المنطقة", Labels.REGIONS, l == null ? null : Json.str(l, "region"), true);
        f.text("sort_order", "الترتيب", l == null ? "0" : String.valueOf(Json.num(l, "sort_order", 0)), "0", Form.INT);
        f.toggle("is_active", "ظاهر في صفحة اللعبة", l == null || Json.bool(l, "is_active"), null);
        final AlertDialog[] d = new AlertDialog[1];
        d[0] = Dialogs.custom(this, l == null ? "رابط جديد" : "تعديل الرابط", f.view(), "حفظ", new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                if (!f.validate()) {
                    return;
                }
                Api.Callback cb = formCallback(f, d, "تم حفظ الرابط");
                if (l == null) {
                    api.post("/admin/games/" + id + "/links", f.values(), cb);
                } else {
                    api.put("/admin/games/" + id + "/links/" + Json.num(l, "id", 0), f.values(), cb);
                }
            }
        });
        if (l != null) {
            deleteButtonIn(f, "حذف الرابط", "/admin/games/" + id + "/links/" + Json.num(l, "id", 0), d[0]);
        }
    }

    private Api.Callback formCallback(final Form f, final AlertDialog[] d, final String okMessage) {
        return new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                if (!alive()) {
                    return;
                }
                if (r.ok) {
                    d[0].dismiss();
                    toast(okMessage);
                    changedOnServer();
                } else {
                    String rest = r.error.fields == null ? r.error.getMessage() : f.showErrors(r.error.fields);
                    if (rest.length() > 0) {
                        toast(rest);
                    }
                }
            }
        };
    }

    // ------------------------------------------------------------------ compatibility

    private void compat(LinearLayout col) {
        List<JSONObject> cs = Json.list(Json.arr(game, "compatibility"));
        final boolean can = session.can("games.edit");
        if (can) {
            col.addView(Ui.button(this, "إضافة نتيجة توافق", R.drawable.ic_plus, Ui.BTN_PRIMARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    editCompat(null);
                }
            }), Ui.fill());
            col.addView(Ui.small(this, "نتيجة لنفس الجهاز/المحاكي تُحدّث النتيجة السابقة."));
            col.addView(Ui.space(this, 12));
        }
        if (cs.isEmpty()) {
            col.addView(Ui.state(this, R.drawable.ic_cpu, Ui.ACCENT, "لا توجد نتائج توافق", "سجّل حالة تشغيل اللعبة على الأجهزة أو المحاكيات المختبرة.", null, null));
        }
        for (final JSONObject c : cs) {
            String status = Json.str(c, "status", "unknown");
            LinearLayout r = Ui.row(this, Ui.iconPlate(this, R.drawable.ic_cpu, Labels.compatColor(status), 38), Json.str(c, "target", ""),
                    (Json.str(c, "notes") != null ? Json.str(c, "notes") : "") + (Json.str(c, "tested_at") != null ? "\nاختُبر: " + Json.str(c, "tested_at") : ""),
                    Ui.chip(this, Json.str(c, "status_label", Labels.of(Labels.COMPAT, status)), Labels.compatColor(status)));
            if (can) {
                r.setOnClickListener(new View.OnClickListener() {
                    @Override
                    public void onClick(View view) {
                        editCompat(c);
                    }
                });
            }
            col.addView(r);
        }
    }

    private void editCompat(final JSONObject c) {
        final Form f = new Form(this);
        f.text("target", "الجهاز أو المحاكي", c == null ? null : Json.str(c, "target"), "مثال: PS4 Pro 11.00 أو RPCS3 0.0.32", Form.TEXT).required().max(64);
        f.select("status", "الحالة", Labels.COMPAT, c == null ? "playable" : Json.str(c, "status"), false).required();
        f.text("notes", "ملاحظات", c == null ? null : Json.str(c, "notes"), "", Form.MULTILINE).max(500);
        f.text("tested_at", "تاريخ الاختبار", c == null ? null : Json.str(c, "tested_at"), "2026-11-25", Form.DATE)
                .pattern("^\\d{4}-\\d{2}-\\d{2}.*$", "اكتب التاريخ بصيغة YYYY-MM-DD");
        final AlertDialog[] d = new AlertDialog[1];
        d[0] = Dialogs.custom(this, c == null ? "نتيجة توافق" : "تعديل النتيجة", f.view(), "حفظ", new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                if (!f.validate()) {
                    return;
                }
                api.post("/admin/games/" + id + "/compat", f.values(), formCallback(f, d, "تم الحفظ"));
            }
        });
        if (c != null) {
            deleteButtonIn(f, "حذف النتيجة", "/admin/games/" + id + "/compat/" + Json.num(c, "id", 0), d[0]);
        }
    }

}
