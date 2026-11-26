package com.upsl.admin;

import android.app.AlertDialog;
import android.app.Dialog;
import android.content.Context;
import android.content.DialogInterface;
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.view.WindowManager;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import java.util.List;
import java.util.Set;

/** Dark, RTL dialogs: confirm, prompt, choose, multi-choose, progress, info. */
public final class Dialogs {
    private Dialogs() {
    }

    public static AlertDialog.Builder builder(Context c) {
        return new AlertDialog.Builder(c, R.style.UpslDialog);
    }

    private static AlertDialog show(AlertDialog.Builder b) {
        AlertDialog d = b.create();
        d.show();
        View decor = d.getWindow() == null ? null : d.getWindow().getDecorView();
        if (decor != null) {
            decor.setLayoutDirection(View.LAYOUT_DIRECTION_RTL);
        }
        restyleButtons(d);
        return d;
    }

    private static void restyleButtons(AlertDialog d) {
        Context c = d.getContext();
        int[] which = {DialogInterface.BUTTON_POSITIVE, DialogInterface.BUTTON_NEGATIVE, DialogInterface.BUTTON_NEUTRAL};
        for (int w : which) {
            TextView b = d.getButton(w);
            if (b != null) {
                b.setTypeface(Ui.font(c, true));
                b.setAllCaps(false);
            }
        }
    }

    private static TextView title(Context c, String s) {
        TextView t = Ui.text(c, s, 17, Ui.INK, true);
        t.setPadding(Ui.dp(c, 22), Ui.dp(c, 20), Ui.dp(c, 22), Ui.dp(c, 4));
        return t;
    }

    private static View message(Context c, CharSequence s) {
        ScrollView sv = new ScrollView(c);
        TextView t = Ui.text(c, s, 14, Ui.MIST, false);
        t.setPadding(Ui.dp(c, 22), Ui.dp(c, 8), Ui.dp(c, 22), Ui.dp(c, 8));
        t.setTextIsSelectable(true);
        sv.addView(t);
        return sv;
    }

    public static void info(Context c, String title, CharSequence msg) {
        show(builder(c).setCustomTitle(title(c, title)).setView(message(c, msg)).setPositiveButton("حسناً", null));
    }

    public static void confirm(Context c, String title, CharSequence msg, String ok, boolean danger, final Runnable onOk) {
        AlertDialog d = show(builder(c).setCustomTitle(title(c, title)).setView(message(c, msg))
                .setPositiveButton(ok, new DialogInterface.OnClickListener() {
                    @Override
                    public void onClick(DialogInterface dialog, int which) {
                        onOk.run();
                    }
                })
                .setNegativeButton("إلغاء", null));
        if (danger) {
            TextView b = d.getButton(DialogInterface.BUTTON_POSITIVE);
            if (b != null) {
                b.setTextColor(Ui.BAD);
            }
        }
    }

    public static void prompt(Context c, String title, String hint, String initial, int inputType, final Ui.Callback<String> cb) {
        LinearLayout box = Ui.vbox(c);
        box.setPadding(Ui.dp(c, 22), Ui.dp(c, 8), Ui.dp(c, 22), 0);
        final EditText e = Form.input(c, hint, inputType);
        if (initial != null) {
            e.setText(initial);
            e.setSelection(e.getText().length());
        }
        box.addView(e);
        AlertDialog d = show(builder(c).setCustomTitle(title(c, title)).setView(box)
                .setPositiveButton("موافق", new DialogInterface.OnClickListener() {
                    @Override
                    public void onClick(DialogInterface dialog, int which) {
                        cb.done(e.getText().toString().trim());
                    }
                })
                .setNegativeButton("إلغاء", null));
        if (d.getWindow() != null) {
            d.getWindow().setSoftInputMode(WindowManager.LayoutParams.SOFT_INPUT_STATE_VISIBLE);
        }
        e.requestFocus();
    }

    /** Password prompt with an explanation (used before restoring a backup). */
    public static void password(Context c, String title, String msg, String ok, final Ui.Callback<String> cb) {
        LinearLayout box = Ui.vbox(c);
        box.setPadding(Ui.dp(c, 22), Ui.dp(c, 8), Ui.dp(c, 22), 0);
        TextView m = Ui.text(c, msg, 14, Ui.MIST, false);
        box.addView(m);
        box.addView(Ui.space(c, 12));
        final EditText e = Form.input(c, "كلمة مرور حسابك", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        box.addView(e);
        AlertDialog d = show(builder(c).setCustomTitle(title(c, title)).setView(box)
                .setPositiveButton(ok, new DialogInterface.OnClickListener() {
                    @Override
                    public void onClick(DialogInterface dialog, int which) {
                        cb.done(e.getText().toString());
                    }
                })
                .setNegativeButton("إلغاء", null));
        TextView b = d.getButton(DialogInterface.BUTTON_POSITIVE);
        if (b != null) {
            b.setTextColor(Ui.BAD);
        }
        e.requestFocus();
    }

    public static void choose(Context c, String title, List<String> labels, int selected, final Ui.IntCallback cb) {
        final String[] items = labels.toArray(new String[0]);
        AlertDialog.Builder b = builder(c).setCustomTitle(title(c, title));
        if (selected >= 0) {
            b.setSingleChoiceItems(items, selected, new DialogInterface.OnClickListener() {
                @Override
                public void onClick(DialogInterface dialog, int which) {
                    dialog.dismiss();
                    cb.done(which);
                }
            });
        } else {
            b.setItems(items, new DialogInterface.OnClickListener() {
                @Override
                public void onClick(DialogInterface dialog, int which) {
                    cb.done(which);
                }
            });
        }
        show(b.setNegativeButton("إلغاء", null));
    }

    public static void multi(Context c, String title, List<String> labels, final Set<Integer> selected, final Runnable onDone) {
        String[] items = labels.toArray(new String[0]);
        boolean[] checked = new boolean[items.length];
        for (int i = 0; i < items.length; i++) {
            checked[i] = selected.contains(i);
        }
        show(builder(c).setCustomTitle(title(c, title))
                .setMultiChoiceItems(items, checked, new DialogInterface.OnMultiChoiceClickListener() {
                    @Override
                    public void onClick(DialogInterface dialog, int which, boolean isChecked) {
                        if (isChecked) {
                            selected.add(which);
                        } else {
                            selected.remove(which);
                        }
                    }
                })
                .setPositiveButton("تم", new DialogInterface.OnClickListener() {
                    @Override
                    public void onClick(DialogInterface dialog, int which) {
                        onDone.run();
                    }
                }));
    }

    /** Custom content dialog (forms); returns the dialog so callers can dismiss it after saving. */
    public static AlertDialog custom(Context c, String title, View content, String ok, final View.OnClickListener onOk) {
        ScrollView sv = new ScrollView(c);
        content.setPadding(Ui.dp(c, 22), Ui.dp(c, 6), Ui.dp(c, 22), Ui.dp(c, 6));
        sv.addView(content);
        final AlertDialog d = show(builder(c).setCustomTitle(title(c, title)).setView(sv)
                .setPositiveButton(ok, null).setNegativeButton("إلغاء", null));
        // keep the dialog open when validation fails: the click handler decides when to dismiss
        TextView b = d.getButton(DialogInterface.BUTTON_POSITIVE);
        if (b != null) {
            b.setOnClickListener(onOk);
        }
        return d;
    }

    /** Blocking progress dialog for short operations. */
    public static Dialog progress(Context c, String msg) {
        Dialog d = new Dialog(c, R.style.UpslDialog);
        LinearLayout box = Ui.hbox(c);
        box.setLayoutDirection(View.LAYOUT_DIRECTION_RTL);
        box.setBackground(Ui.outline(c, Ui.CARD_SOLID, Ui.STROKE, 18));
        Ui.pad(box, 20);
        box.addView(Ui.spinner(c));
        box.addView(Ui.hspace(c, 16));
        TextView t = Ui.text(c, msg, 14, Ui.INK, false);
        t.setGravity(Gravity.CENTER_VERTICAL);
        box.addView(t, Ui.weight(1));
        d.setContentView(box);
        d.setCancelable(false);
        if (d.getWindow() != null) {
            d.getWindow().setBackgroundDrawableResource(android.R.color.transparent);
        }
        d.show();
        return d;
    }
}
