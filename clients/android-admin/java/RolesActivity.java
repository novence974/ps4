package com.upsl.admin;

import android.os.Bundle;
import android.view.View;
import android.widget.CompoundButton;
import android.widget.LinearLayout;
import android.widget.Switch;
import android.widget.TextView;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;

/** Roles and the permission matrix (only the Super Admin can change it; the Super Admin role always has everything). */
public class RolesActivity extends Screen {
    private List<JSONObject> roles;
    private List<JSONObject> permissions;
    private final Map<Integer, Set<Integer>> matrix = new HashMap<Integer, Set<Integer>>();
    private boolean canEdit;
    private int selected;
    private boolean dirty;

    @Override
    protected void build(Bundle saved) {
        setTitleText("الأدوار والصلاحيات");
        if (!require("roles.assign")) {
            return;
        }
        load();
    }

    private void load() {
        showLoading();
        api.get("/admin/roles", null, new Api.Callback() {
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
                apply(r.obj());
                render();
            }
        });
    }

    private void apply(JSONObject d) {
        roles = Json.list(Json.arr(d, "roles"));
        permissions = Json.list(Json.arr(d, "permissions"));
        canEdit = Json.bool(d, "can_edit");
        matrix.clear();
        JSONObject m = Json.obj(d, "matrix");
        for (JSONObject role : roles) {
            int rid = Json.num(role, "id", 0);
            Set<Integer> set = new HashSet<Integer>();
            JSONArray a = m == null ? null : m.optJSONArray(String.valueOf(rid));
            if (a != null) {
                for (int i = 0; i < a.length(); i++) {
                    set.add(a.optInt(i));
                }
            }
            matrix.put(rid, set);
        }
        dirty = false;
    }

    private static String group(String g) {
        if ("general".equals(g)) {
            return "عام";
        }
        if ("games".equals(g)) {
            return "الألعاب";
        }
        if ("files".equals(g)) {
            return "الملفات";
        }
        if ("catalog".equals(g)) {
            return "الكتالوج";
        }
        if ("users".equals(g)) {
            return "المستخدمون";
        }
        if ("system".equals(g)) {
            return "النظام";
        }
        return g;
    }

    private void render() {
        LinearLayout col = page();
        if (!canEdit) {
            col.addView(Ui.small(this, "عرض فقط: تعديل مصفوفة الصلاحيات متاح للمدير الرئيسي."));
            col.addView(Ui.space(this, 8));
        }
        List<String> labels = new ArrayList<String>();
        for (JSONObject r : roles) {
            labels.add(Json.str(r, "name", "") + " (" + Json.num(r, "level", 0) + ")");
        }
        col.addView(Ui.pillRow(this, labels, selected, new Ui.IntCallback() {
            @Override
            public void done(int value) {
                selected = value;
                render();
            }
        }));
        final JSONObject role = roles.get(selected);
        final int rid = Json.num(role, "id", 0);
        final boolean editable = canEdit && Json.bool(role, "editable");
        if (!Json.bool(role, "editable")) {
            col.addView(Ui.small(this, "دور المدير الرئيسي يملك كل الصلاحيات دائماً ولا يمكن تقليصها."));
            col.addView(Ui.space(this, 8));
        }
        Map<String, List<JSONObject>> groups = new LinkedHashMap<String, List<JSONObject>>();
        for (JSONObject p : permissions) {
            String g = Json.str(p, "group", "general");
            if (!groups.containsKey(g)) {
                groups.put(g, new ArrayList<JSONObject>());
            }
            groups.get(g).add(p);
        }
        final Set<Integer> granted = matrix.get(rid);
        for (Map.Entry<String, List<JSONObject>> e : groups.entrySet()) {
            LinearLayout card = Ui.card(this);
            card.addView(Ui.h2(this, group(e.getKey())));
            for (JSONObject p : e.getValue()) {
                final int pid = Json.num(p, "id", 0);
                LinearLayout row = Ui.hbox(this);
                row.setPadding(0, Ui.dp(this, 6), 0, Ui.dp(this, 6));
                LinearLayout t = Ui.vbox(this);
                t.addView(Ui.text(this, Json.str(p, "name", ""), 14, Ui.INK, false));
                TextView slug = Ui.small(this, Json.str(p, "slug", ""));
                Ui.ltrText(slug);
                t.addView(slug);
                row.addView(t, Ui.weight(1));
                Switch sw = new Switch(this);
                sw.setChecked(granted != null && granted.contains(pid));
                sw.setEnabled(editable);
                sw.setOnCheckedChangeListener(new CompoundButton.OnCheckedChangeListener() {
                    @Override
                    public void onCheckedChanged(CompoundButton b, boolean on) {
                        if (on) {
                            granted.add(pid);
                        } else {
                            granted.remove(pid);
                        }
                        dirty = true;
                    }
                });
                row.addView(sw);
                card.addView(row);
            }
            col.addView(card);
        }
        if (canEdit) {
            col.addView(Ui.button(this, "حفظ المصفوفة", R.drawable.ic_save, Ui.BTN_PRIMARY, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    save();
                }
            }), Ui.fill());
            col.addView(Ui.small(this, "تنطبق التغييرات فوراً على كل حسابات الدور، بما في ذلك الجلسات المفتوحة."));
        }
    }

    private void save() {
        JSONObject m = new JSONObject();
        for (JSONObject role : roles) {
            if (!Json.bool(role, "editable")) {
                continue;
            }
            int rid = Json.num(role, "id", 0);
            JSONArray a = new JSONArray();
            for (Integer p : matrix.get(rid)) {
                a.put(p);
            }
            Json.put(m, String.valueOf(rid), a);
        }
        busy("جارٍ الحفظ…");
        api.put("/admin/roles", Json.of("matrix", m), new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                busy(null);
                if (!alive()) {
                    return;
                }
                if (r.ok) {
                    apply(r.obj());
                    toast("حُفظت الصلاحيات");
                    render();
                } else {
                    fail(r);
                }
            }
        });
    }

    @Override
    public void onBackPressed() {
        if (dirty && canEdit) {
            Dialogs.confirm(this, "تغييرات غير محفوظة", "لم تحفظ تعديلات الصلاحيات. الخروج دون حفظ؟", "خروج دون حفظ", true, new Runnable() {
                @Override
                public void run() {
                    dirty = false;
                    finish();
                }
            });
            return;
        }
        super.onBackPressed();
    }
}
