package com.upsl.admin;

import android.app.Dialog;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import android.widget.LinearLayout;

import org.json.JSONObject;

import java.io.OutputStream;
import java.util.List;

/** Backups: create (database only or with media), download to the phone, upload, restore (password required) and delete. */
public class BackupsActivity extends Screen {
    private static final int REQ_SAVE = 7401;
    private JSONObject meta;
    private String pendingDownload;

    @Override
    protected void build(Bundle saved) {
        setTitleText("النسخ الاحتياطي");
        if (!require("backups.manage")) {
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
        api.get("/admin/backups", null, new Api.Callback() {
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
                meta = r.meta == null ? new JSONObject() : r.meta;
                render(Json.list(r.arr()));
            }
        });
    }

    private void render(List<JSONObject> backups) {
        LinearLayout col = page();
        LinearLayout c = Ui.card(this);
        c.addView(Ui.h2(this, "نسخة جديدة"));
        c.addView(Ui.kv(this, "النسخ التلقائي", Labels.of(Labels.BACKUP_FREQUENCY, Json.str(meta, "frequency", "off")) + " — يُحتفظ بآخر " + Json.num(meta, "keep", 0)));
        c.addView(Ui.kv(this, "آخر نسخة تلقائية", Json.str(meta, "last_run") == null ? "—" : Json.str(meta, "last_run")));
        c.addView(Ui.space(this, 10));
        c.addView(Ui.button(this, "نسخ قاعدة البيانات", R.drawable.ic_database, Ui.BTN_PRIMARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                create(false);
            }
        }), Ui.fill());
        c.addView(Ui.space(this, 8));
        c.addView(Ui.button(this, "نسخة كاملة مع الصور والوسائط", R.drawable.ic_archive, Ui.BTN_SECONDARY, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                create(true);
            }
        }), Ui.fill());
        c.addView(Ui.small(this, "ملفات الألعاب الكبيرة لا تدخل في النسخ؛ انسخ مجلد التخزين بأداة النظام. ضبط النسخ التلقائي من «الإعدادات ← النسخ الاحتياطي»."));
        if (session.can("backups.restore")) {
            c.addView(Ui.space(this, 8));
            c.addView(Ui.button(this, "رفع نسخة من الجهاز", R.drawable.ic_upload, Ui.BTN_GHOST, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    pickFile(new String[]{"application/gzip", "application/x-gzip", "application/zip", "application/sql", "application/octet-stream"}, new Ui.Callback<Uri>() {
                        @Override
                        public void done(Uri uri) {
                            askNotifications();
                            Transfers.get(BackupsActivity.this).enqueue(BackupsActivity.this, uri, "backup", new JSONObject(), "نسخة احتياطية");
                            toast("بدأ رفع النسخة؛ ستظهر هنا بعد اكتمالها");
                        }
                    });
                }
            }), Ui.fill());
        }
        col.addView(c);

        col.addView(Ui.h2(this, "النسخ المحفوظة على الخادم (" + backups.size() + ")"));
        if (backups.isEmpty()) {
            col.addView(Ui.muted(this, "لا توجد نسخ بعد."));
        }
        for (final JSONObject b : backups) {
            boolean full = "full".equals(Json.str(b, "type"));
            LinearLayout r = Ui.row(this, Ui.iconPlate(this, full ? R.drawable.ic_archive : R.drawable.ic_database, full ? Ui.ACCENT : Ui.ACCENT2, 40),
                    Json.str(b, "name", ""), (full ? "كاملة" : "قاعدة البيانات") + " • " + Json.str(b, "size_human", "") + " • " + Fmt.date(Json.str(b, "created_at")), null);
            r.setOnClickListener(new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    actions(b);
                }
            });
            col.addView(r);
        }
    }

    private void create(boolean media) {
        busy(media ? "جارٍ إنشاء نسخة كاملة… قد يستغرق ذلك دقائق" : "جارٍ نسخ قاعدة البيانات…");
        api.post("/admin/backups", Json.of("include_media", media), new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                busy(null);
                if (!alive()) {
                    return;
                }
                if (r.ok) {
                    toast("أُنشئت النسخة " + Json.str(r.obj(), "name", ""));
                    load();
                } else {
                    fail(r);
                }
            }
        });
    }

    private void actions(final JSONObject b) {
        final String name = Json.str(b, "name", "");
        final boolean canRestore = Json.bool(meta, "can_restore");
        java.util.List<String> labels = new java.util.ArrayList<String>();
        labels.add("تنزيل إلى الجهاز");
        if (canRestore) {
            labels.add("استعادة هذه النسخة");
        }
        labels.add("حذف");
        Dialogs.choose(this, name, labels, -1, new Ui.IntCallback() {
            @Override
            public void done(int which) {
                if (which == 0) {
                    download(name);
                } else if (canRestore && which == 1) {
                    restore(b);
                } else {
                    Dialogs.confirm(BackupsActivity.this, "حذف النسخة", "ستُحذف «" + name + "» من الخادم.", "حذف", true, new Runnable() {
                        @Override
                        public void run() {
                            api.delete("/admin/backups/" + name, new Api.Callback() {
                                @Override
                                public void done(Api.Result r) {
                                    if (!alive()) {
                                        return;
                                    }
                                    if (r.ok) {
                                        toast("حُذفت النسخة");
                                        load();
                                    } else {
                                        fail(r);
                                    }
                                }
                            });
                        }
                    });
                }
            }
        });
    }

    private void restore(JSONObject b) {
        final String name = Json.str(b, "name", "");
        final boolean full = "full".equals(Json.str(b, "type"));
        Dialogs.password(this, "استعادة النسخة",
                "ستُستبدل بيانات الموقع الحالية بمحتوى «" + name + "»" + (full ? " (مع الصور والوسائط)" : "")
                        + ". يُنشئ الخادم نسخة أمان من الوضع الحالي قبل الاستعادة. اكتب كلمة مرور حسابك للتأكيد.",
                "استعادة", new Ui.Callback<String>() {
                    @Override
                    public void done(String password) {
                        if (password.length() == 0) {
                            return;
                        }
                        busy("جارٍ الاستعادة… لا تغلق التطبيق");
                        api.post("/admin/backups/" + name + "/restore", Json.of("password", password, "with_media", full), new Api.Callback() {
                            @Override
                            public void done(Api.Result r) {
                                busy(null);
                                if (!alive()) {
                                    return;
                                }
                                if (r.ok) {
                                    Dialogs.info(BackupsActivity.this, "تمت الاستعادة", "استُعيدت «" + name + "».\nنسخة الأمان من الوضع السابق: "
                                            + Json.str(r.obj(), "safety_backup", "—") + "\n\nإن تغيرت كلمات المرور أو الحسابات بعد الاستعادة فقد تحتاج لتسجيل الدخول مجدداً.");
                                    load();
                                } else {
                                    fail(r);
                                }
                            }
                        });
                    }
                });
    }

    private void download(String name) {
        pendingDownload = name;
        Intent i = new Intent(Intent.ACTION_CREATE_DOCUMENT);
        i.addCategory(Intent.CATEGORY_OPENABLE);
        i.setType("application/octet-stream");
        i.putExtra(Intent.EXTRA_TITLE, name);
        try {
            startActivityForResult(i, REQ_SAVE);
        } catch (RuntimeException e) {
            toast("لا يوجد مدير ملفات لحفظ النسخة");
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != REQ_SAVE || resultCode != RESULT_OK || data == null || data.getData() == null || pendingDownload == null) {
            return;
        }
        final Uri target = data.getData();
        final String name = pendingDownload;
        pendingDownload = null;
        final Dialog d = Dialogs.progress(this, "جارٍ تنزيل " + name + "…");
        api.runBackground(new Runnable() {
            @Override
            public void run() {
                String error = null;
                long bytes = 0;
                try {
                    OutputStream out = getContentResolver().openOutputStream(target);
                    if (out == null) {
                        throw new java.io.IOException("تعذر فتح ملف الحفظ");
                    }
                    bytes = api.download("/admin/backups/" + name + "/download", out, null);
                } catch (Api.Error e) {
                    error = e.getMessage();
                } catch (java.io.IOException e) {
                    error = Api.networkError(e).getMessage();
                }
                final String err = error;
                final long size = bytes;
                api.main().post(new Runnable() {
                    @Override
                    public void run() {
                        try {
                            d.dismiss();
                        } catch (RuntimeException ignored) {
                        }
                        if (alive()) {
                            toast(err == null ? "حُفظت النسخة (" + Fmt.bytes(size) + ")" : err);
                        }
                    }
                });
            }
        });
    }
}
