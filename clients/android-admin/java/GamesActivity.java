package com.upsl.admin;

import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;
import android.widget.TextView;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;

/** All games (published and drafts) with search, filters, sorting and bulk actions (long-press to select). */
public class GamesActivity extends ListScreen {
    private final int[] status = {0};
    private final int[] sort = {0};
    private String platformSlug;
    private String platformName;
    private TextView platformPicker;
    private TextView totalView;
    private final Set<Integer> selected = new LinkedHashSet<Integer>();
    private LinearLayout selectionBar;
    private TextView selectionCount;
    private List<JSONObject> platforms = new ArrayList<JSONObject>();

    private static final String[] SORT_KEYS = {"newest", "updated", "title", "downloads", "rating", "year"};
    private static final String[] SORT_LABELS = {"الأحدث إضافة", "آخر تعديل", "الاسم", "الأكثر تنزيلاً", "الأعلى تقييماً", "سنة الإصدار"};

    @Override
    protected String endpoint() {
        return "/admin/games";
    }

    @Override
    protected String searchHint() {
        return "ابحث بالاسم أو رمز المنتج أو المطور";
    }

    @Override
    protected void query(Map<String, String> q) {
        switch (status[0]) {
            case 1:
                q.put("published", "1");
                break;
            case 2:
                q.put("published", "0");
                break;
            case 3:
                q.put("featured", "1");
                break;
            case 4:
                q.put("availability", "unavailable");
                break;
            default:
                break;
        }
        if (platformSlug != null) {
            q.put("platform", platformSlug);
        }
        q.put("sort", SORT_KEYS[sort[0]]);
    }

    @Override
    protected void build(Bundle saved) {
        setTitleText("الألعاب");
        if (session.can("games.create")) {
            addAction(R.drawable.ic_plus, "إضافة لعبة", new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    open(GameFormActivity.class);
                }
            });
        }
        addAction(R.drawable.ic_sort, "الترتيب", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                Dialogs.choose(GamesActivity.this, "ترتيب الألعاب", Arrays.asList(SORT_LABELS), sort[0], new Ui.IntCallback() {
                    @Override
                    public void done(int value) {
                        sort[0] = value;
                        reload();
                    }
                });
            }
        });
        super.build(saved);
        api.get("/admin/platforms", null, new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                if (r.ok) {
                    platforms = Json.list(r.arr());
                }
            }
        });
    }

    @Override
    protected void buildHeader(LinearLayout header) {
        header.addView(filters(Arrays.asList("الكل", "منشورة", "مسودات", "مميزة", "بلا ملفات"), status, new Runnable() {
            @Override
            public void run() {
                reload();
            }
        }));
        LinearLayout line = Ui.hbox(this);
        line.setPadding(0, 0, 0, Ui.dp(this, 8));
        platformPicker = Ui.pill(this, "كل الأجهزة", false, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                pickPlatform();
            }
        });
        line.addView(platformPicker);
        totalView = Ui.small(this, "");
        line.addView(totalView, Ui.weight(1));
        header.addView(line);
        selectionBar = Ui.hbox(this);
        selectionBar.setBackground(Ui.outline(this, Ui.alpha(Ui.ACCENT, 0.16f), Ui.alpha(Ui.ACCENT, 0.5f), 14));
        selectionBar.setPadding(Ui.dp(this, 12), Ui.dp(this, 6), Ui.dp(this, 6), Ui.dp(this, 6));
        selectionCount = Ui.text(this, "", 14, Ui.INK, true);
        selectionBar.addView(selectionCount, Ui.weight(1));
        selectionBar.addView(Ui.button(this, "إجراء", R.drawable.ic_list_checks, Ui.BTN_SECONDARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                bulkMenu();
            }
        }));
        selectionBar.addView(Ui.iconButton(this, R.drawable.ic_x, "إلغاء التحديد", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                selected.clear();
                updateSelection();
            }
        }));
        selectionBar.setVisibility(View.GONE);
        LinearLayout.LayoutParams p = Ui.fill();
        p.bottomMargin = Ui.dp(this, 8);
        header.addView(selectionBar, p);
    }

    @Override
    protected void onLoaded(Api.Result r) {
        int total = r.metaInt("total", items.size());
        totalView.setText("  " + Fmt.count(total, "لعبة واحدة", "لعبتان", "ألعاب"));
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (list != null && consumeDirty("games")) {
            reload();
        }
    }

    private void pickPlatform() {
        final List<String> labels = new ArrayList<String>();
        labels.add("كل الأجهزة");
        for (JSONObject p : platforms) {
            labels.add(Json.str(p, "name", "") + " (" + Json.num(p, "games_count", 0) + ")");
        }
        Dialogs.choose(this, "الجهاز", labels, -1, new Ui.IntCallback() {
            @Override
            public void done(int which) {
                if (which == 0) {
                    platformSlug = null;
                    platformName = null;
                } else {
                    JSONObject p = platforms.get(which - 1);
                    platformSlug = Json.str(p, "slug");
                    platformName = Json.str(p, "short_name", Json.str(p, "name", ""));
                }
                platformPicker.setText(platformName == null ? "كل الأجهزة" : platformName);
                reload();
            }
        });
    }

    @Override
    protected View row(JSONObject g, int position) {
        final int id = Json.num(g, "id", 0);
        JSONObject plat = Json.obj(g, "platform");
        View cover = Ui.remote(this, ImageLoader.pick(Json.obj(g, "cover"), "sm", "sm_jpg"), 46, 64, 10);
        StringBuilder sub = new StringBuilder();
        sub.append(Json.str(plat, "short_name", "?"));
        if (Json.str(g, "region") != null) {
            sub.append(" • ").append(Labels.of(Labels.REGIONS, Json.str(g, "region")));
        }
        if (Json.num(g, "release_year", 0) > 0) {
            sub.append(" • ").append(Json.num(g, "release_year", 0));
        }
        if (Json.str(g, "product_code") != null) {
            sub.append(" • ").append(Fmt.ltr(Json.str(g, "product_code")));
        }
        sub.append("\n").append(Labels.of(Labels.FILES_STATUS, Json.str(g, "files_status", "unavailable")))
                .append(" • ").append(Fmt.num(Json.lng(g, "downloads", 0))).append(" تنزيل");
        LinearLayout chips = Ui.vbox(this);
        chips.setGravity(android.view.Gravity.END);
        boolean pub = Json.bool(g, "is_published");
        chips.addView(Ui.chip(this, pub ? "منشورة" : "مسودة", pub ? Ui.OK : Ui.WARN));
        if (Json.bool(g, "is_featured")) {
            chips.addView(Ui.space(this, 4));
            chips.addView(Ui.chip(this, "مميزة", Ui.ACCENT));
        }
        LinearLayout r = Ui.row(this, cover, Json.str(g, "title", ""), sub.toString(), chips);
        if (selected.contains(id)) {
            r.setBackground(Ui.outline(this, Ui.alpha(Ui.ACCENT, 0.18f), Ui.ACCENT, 16));
        }
        return r;
    }

    @Override
    protected void onItem(JSONObject g) {
        int id = Json.num(g, "id", 0);
        if (!selected.isEmpty()) {
            toggle(id);
            return;
        }
        open(GameActivity.class, "id", id);
    }

    @Override
    protected boolean onItemLong(JSONObject g) {
        if (!session.can("games.publish", "games.delete")) {
            return false;
        }
        toggle(Json.num(g, "id", 0));
        return true;
    }

    private void toggle(int id) {
        if (!selected.remove(id)) {
            selected.add(id);
        }
        updateSelection();
    }

    private void updateSelection() {
        selectionBar.setVisibility(selected.isEmpty() ? View.GONE : View.VISIBLE);
        selectionCount.setText("محدد: " + Fmt.count(selected.size(), "لعبة واحدة", "لعبتان", "ألعاب"));
        refreshRows();
    }

    private void bulkMenu() {
        final List<String> keys = new ArrayList<String>();
        final List<String> labels = new ArrayList<String>();
        if (session.can("games.publish")) {
            keys.addAll(Arrays.asList("publish", "hide", "feature", "unfeature"));
            labels.addAll(Arrays.asList("نشر", "إخفاء (مسودة)", "تمييز في الواجهة", "إلغاء التمييز"));
        }
        if (session.can("games.delete")) {
            keys.add("delete");
            labels.add("حذف نهائي مع الملفات");
        }
        Dialogs.choose(this, "إجراء على " + selected.size() + " محدد", labels, -1, new Ui.IntCallback() {
            @Override
            public void done(int which) {
                final String action = keys.get(which);
                if ("delete".equals(action)) {
                    Dialogs.confirm(GamesActivity.this, "حذف الألعاب المحددة",
                            "سيُحذف " + Fmt.count(selected.size(), "لعبة واحدة", "لعبتان", "ألعاب") + " مع صورها وملفاتها المستضافة. لا يمكن التراجع.",
                            "حذف", true, new Runnable() {
                                @Override
                                public void run() {
                                    bulk(action);
                                }
                            });
                } else {
                    bulk(action);
                }
            }
        });
    }

    private void bulk(String action) {
        JSONArray ids = new JSONArray();
        for (Integer id : selected) {
            ids.put(id);
        }
        busy("جارٍ التنفيذ…");
        api.post("/admin/games/bulk", Json.of("ids", ids, "action", action), new Api.Callback() {
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
                int done = Json.num(r.obj(), "done", 0);
                toast("تم التنفيذ على " + Fmt.count(done, "لعبة واحدة", "لعبتين", "ألعاب"));
                selected.clear();
                updateSelection();
                reload();
            }
        });
    }

    @Override
    protected String emptyTitle() {
        return "لا توجد ألعاب مطابقة";
    }

    @Override
    protected String emptyBody() {
        return "غيّر الفلاتر أو أضف لعبة جديدة من زر +";
    }

    @Override
    protected int emptyIcon() {
        return R.drawable.ic_gamepad;
    }
}
