package com.upsl.admin;

import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;

import org.json.JSONObject;

import java.util.Arrays;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

/** Uploads from this phone (live progress, pause / resume / cancel) and every upload session on the server. */
public class UploadsActivity extends ListScreen implements Transfers.Listener {
    private final int[] status = {0};
    private static final String[] STATUS_KEYS = {"", "uploading", "processing", "completed", "failed", "cancelled"};
    private LinearLayout local;
    private final Map<String, View[]> views = new HashMap<String, View[]>();

    @Override
    protected String endpoint() {
        return "/admin/uploads";
    }

    @Override
    protected void query(Map<String, String> q) {
        if (status[0] > 0) {
            q.put("status", STATUS_KEYS[status[0]]);
        }
    }

    @Override
    protected void build(Bundle saved) {
        setTitleText("عمليات الرفع");
        if (!require("files.upload", "media.upload", "files.manage")) {
            return;
        }
        addAction(R.drawable.ic_refresh, "تحديث", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                reload();
                renderLocal();
            }
        });
        super.build(saved);
    }

    @Override
    protected void buildHeader(LinearLayout header) {
        local = Ui.vbox(this);
        header.addView(local, Ui.fill());
        renderLocal();
        header.addView(Ui.text(this, "سجل الرفع على الخادم", 15, Ui.INK, true));
        header.addView(Ui.space(this, 6));
        header.addView(filters(Arrays.asList("الكل", "قيد الرفع", "قيد المعالجة", "مكتمل", "فشل", "ملغى"), status, new Runnable() {
            @Override
            public void run() {
                reload();
            }
        }));
    }

    @Override
    protected void onResume() {
        super.onResume();
        Transfers.get(this).addListener(this);
        if (local != null) {
            renderLocal();
        }
    }

    @Override
    protected void onPause() {
        Transfers.get(this).removeListener(this);
        super.onPause();
    }

    @Override
    public void changed(Transfers.Task t) {
        if (!alive() || local == null) {
            return;
        }
        View[] v = views.get(t.id);
        if (v == null) {
            renderLocal();
            return;
        }
        update(t, v);
        if (!t.active()) {
            renderLocal();
            if (Transfers.DONE.equals(t.status)) {
                reload();
            }
        }
    }

    private void renderLocal() {
        local.removeAllViews();
        views.clear();
        final Transfers tr = Transfers.get(this);
        List<Transfers.Task> tasks = tr.all();
        LinearLayout head = Ui.hbox(this);
        head.addView(Ui.text(this, "من هذا الجهاز", 15, Ui.INK, true), Ui.weight(1));
        if (!tasks.isEmpty()) {
            head.addView(Ui.button(this, "مسح المنتهية", 0, Ui.BTN_GHOST, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    tr.clearFinished();
                    renderLocal();
                }
            }));
        }
        local.addView(head);
        local.addView(Ui.space(this, 6));
        if (tasks.isEmpty()) {
            TextView t = Ui.muted(this, "لا توجد عمليات رفع من هذا الجهاز. ابدأ الرفع من صفحة اللعبة (تبويب «الملفات» أو «الوسائط»).");
            t.setPadding(0, 0, 0, Ui.dp(this, 12));
            local.addView(t);
            return;
        }
        for (final Transfers.Task t : tasks) {
            LinearLayout c = Ui.card(this);
            LinearLayout top = Ui.hbox(this);
            top.addView(Ui.iconPlate(this, R.drawable.ic_cloud_upload, Ui.ACCENT2, 36));
            top.addView(Ui.hspace(this, 10));
            LinearLayout info = Ui.vbox(this);
            TextView name = Ui.single(Ui.text(this, t.name, 14, Ui.INK, true));
            Ui.ltrText(name);
            info.addView(name);
            info.addView(Ui.small(this, Labels.of(Labels.UPLOAD_PURPOSES, t.purpose) + (t.title != null && t.title.length() > 0 ? " — " + t.title : "")
                    + " • " + Fmt.bytes(t.size)));
            top.addView(info, Ui.weight(1));
            TextView chip = Ui.chip(this, "", Ui.ACCENT2);
            top.addView(chip);
            c.addView(top);
            c.addView(Ui.space(this, 10));
            ProgressBar bar = Ui.progress(this);
            c.addView(bar);
            TextView line = Ui.small(this, "");
            line.setPadding(0, Ui.dp(this, 6), 0, 0);
            c.addView(line);
            LinearLayout buttons = Ui.hbox(this);
            buttons.setPadding(0, Ui.dp(this, 8), 0, 0);
            c.addView(buttons);
            views.put(t.id, new View[]{chip, bar, line, buttons});
            update(t, views.get(t.id));
            local.addView(c);
        }
    }

    private void update(final Transfers.Task t, View[] v) {
        TextView chip = (TextView) v[0];
        ProgressBar bar = (ProgressBar) v[1];
        TextView line = (TextView) v[2];
        LinearLayout buttons = (LinearLayout) v[3];
        String label;
        int color;
        if (Transfers.UPLOADING.equals(t.status)) {
            label = Math.round(t.fraction() * 100) + "٪";
            color = Ui.ACCENT2;
        } else if (Transfers.FINISHING.equals(t.status) || Transfers.PROCESSING.equals(t.status)) {
            label = "تحقق وتجميع";
            color = Ui.WARN;
        } else if (Transfers.DONE.equals(t.status)) {
            label = "اكتمل";
            color = Ui.OK;
        } else if (Transfers.FAILED.equals(t.status)) {
            label = "فشل";
            color = Ui.BAD;
        } else if (Transfers.PAUSED.equals(t.status)) {
            label = "متوقف";
            color = Ui.WARN;
        } else if (Transfers.CANCELLED.equals(t.status)) {
            label = "ملغى";
            color = Ui.FAINT;
        } else {
            label = "في الانتظار";
            color = Ui.MIST;
        }
        chip.setText(label);
        chip.setTextColor(color);
        chip.setBackground(Ui.outline(this, Ui.alpha(color, 0.14f), Ui.alpha(color, 0.38f), 999));
        bar.setIndeterminate(Transfers.FINISHING.equals(t.status) || Transfers.PROCESSING.equals(t.status));
        bar.setProgress((int) (t.fraction() * 1000));
        StringBuilder sb = new StringBuilder();
        sb.append(Fmt.bytes(t.sent)).append(" من ").append(Fmt.bytes(t.size));
        if (Transfers.UPLOADING.equals(t.status) && t.speed > 0) {
            sb.append(" • ").append(Fmt.bytes((long) t.speed)).append("/ث • متبقٍ ").append(Fmt.duration((t.size - t.sent) / t.speed));
        }
        if (t.error != null) {
            sb.append("\n").append(t.error);
        }
        if (Transfers.DONE.equals(t.status) && t.result != null && Json.num(t.result, "file_id", 0) > 0) {
            sb.append("\nسُجّل الملف رقم #").append(Json.num(t.result, "file_id", 0)).append(" وأصبح ضمن ملفات اللعبة");
        }
        line.setText(sb.toString());
        line.setTextColor(t.error != null && !Transfers.UPLOADING.equals(t.status) ? Ui.WARN : Ui.FAINT);
        buttons.removeAllViews();
        final Transfers tr = Transfers.get(this);
        if (Transfers.UPLOADING.equals(t.status) || Transfers.QUEUED.equals(t.status)) {
            buttons.addView(Ui.button(this, "إيقاف مؤقت", R.drawable.ic_pause, Ui.BTN_SECONDARY, new View.OnClickListener() {
                @Override
                public void onClick(View view) {
                    tr.pause(t);
                }
            }));
        }
        if (Transfers.PAUSED.equals(t.status) || Transfers.FAILED.equals(t.status)) {
            buttons.addView(Ui.button(this, "استئناف", R.drawable.ic_play, Ui.BTN_PRIMARY, new View.OnClickListener() {
                @Override
                public void onClick(View view) {
                    askNotifications();
                    tr.resume(UploadsActivity.this, t);
                    renderLocal();
                }
            }));
        }
        if (t.active() || Transfers.PAUSED.equals(t.status)) {
            buttons.addView(Ui.hspace(this, 8));
            buttons.addView(Ui.button(this, "إلغاء", R.drawable.ic_x, Ui.BTN_DANGER, new View.OnClickListener() {
                @Override
                public void onClick(View view) {
                    Dialogs.confirm(UploadsActivity.this, "إلغاء الرفع", "ستُحذف الأجزاء المرفوعة من الخادم.", "إلغاء الرفع", true, new Runnable() {
                        @Override
                        public void run() {
                            tr.cancel(t);
                            renderLocal();
                        }
                    });
                }
            }));
        } else {
            buttons.addView(Ui.button(this, "إزالة من القائمة", 0, Ui.BTN_GHOST, new View.OnClickListener() {
                @Override
                public void onClick(View view) {
                    tr.remove(t);
                    renderLocal();
                }
            }));
        }
    }

    @Override
    protected View row(JSONObject u, int position) {
        String st = Json.str(u, "status", "");
        JSONObject game = Json.obj(u, "game");
        StringBuilder sub = new StringBuilder();
        sub.append(Labels.of(Labels.UPLOAD_PURPOSES, Json.str(u, "purpose"))).append(" • ").append(Fmt.bytes(Json.lng(u, "size", 0)));
        if ("uploading".equals(st)) {
            sub.append(" • ").append(Json.str(u, "progress", "0")).append("٪");
        }
        if (game != null) {
            sub.append("\n").append(Json.str(game, "title", ""));
        }
        sub.append("\n").append(Json.str(u, "user", "?")).append(" • ").append(Fmt.ago(Json.str(u, "updated_at")));
        if (Json.str(u, "error") != null) {
            sub.append("\n⚠ ").append(Json.str(u, "error"));
        }
        return Ui.row(this, Ui.iconPlate(this, R.drawable.ic_file, Labels.uploadColor(st), 36), Json.str(u, "filename", ""), sub.toString(),
                Ui.chip(this, Labels.of(Labels.UPLOAD_STATUS, st), Labels.uploadColor(st)));
    }

    @Override
    protected void onItem(final JSONObject u) {
        if (!"uploading".equals(Json.str(u, "status"))) {
            if (Json.str(u, "sha256") != null) {
                Dialogs.info(this, Json.str(u, "filename", ""), "SHA-256:\n" + Json.str(u, "sha256"));
            }
            return;
        }
        Dialogs.confirm(this, "إلغاء عملية الرفع", "عملية رفع غير مكتملة للملف «" + Json.str(u, "filename", "") + "». إلغاؤها يحذف الأجزاء المستلمة من الخادم.",
                "إلغاء العملية", true, new Runnable() {
                    @Override
                    public void run() {
                        api.delete("/admin/uploads/" + Json.str(u, "upload_id"), new Api.Callback() {
                            @Override
                            public void done(Api.Result r) {
                                if (!alive()) {
                                    return;
                                }
                                if (r.ok) {
                                    toast("أُلغيت العملية");
                                    reload();
                                } else {
                                    fail(r);
                                }
                            }
                        });
                    }
                });
    }

    @Override
    protected String emptyTitle() {
        return "لا توجد عمليات رفع";
    }

    @Override
    protected int emptyIcon() {
        return R.drawable.ic_cloud_upload;
    }
}
