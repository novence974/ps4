package com.upsl.admin;

import android.content.Context;
import android.graphics.drawable.GradientDrawable;
import android.graphics.drawable.StateListDrawable;
import android.text.Editable;
import android.text.InputFilter;
import android.text.InputType;
import android.text.TextWatcher;
import android.view.Gravity;
import android.view.View;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.Switch;
import android.widget.TextView;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.TreeSet;
import java.util.regex.Pattern;

/**
 * Small form builder: labelled fields, client-side validation that mirrors the server rules,
 * typed JSON output and server-side field errors shown under the matching field.
 */
public final class Form {
    public static final int TEXT = 0;
    public static final int MULTILINE = 1;
    public static final int INT = 2;
    public static final int DECIMAL = 3;
    public static final int SELECT = 4;
    public static final int MULTI = 5;
    public static final int SWITCH = 6;
    public static final int COLOR = 7;
    public static final int PASSWORD = 8;
    public static final int URL = 9;
    public static final int EMAIL = 10;
    public static final int CODE = 11;
    public static final int DATE = 12;

    public final class Field {
        public final String key;
        public final String label;
        public final int type;
        public final LinearLayout block;
        public View input;
        public TextView error;
        public TextView help;
        public String[][] options;
        public boolean allowEmpty;
        public int selected = -1;
        public final Set<Integer> multi = new TreeSet<Integer>();
        public boolean required;
        public int maxLength;
        public Pattern pattern;
        public String patternMessage;
        public Double min;
        public Double max;
        public String[] aliases = new String[0];

        Field(String key, String label, int type, LinearLayout block) {
            this.key = key;
            this.label = label;
            this.type = type;
            this.block = block;
        }

        public Field required() {
            required = true;
            TextView l = (TextView) block.getChildAt(0);
            l.setText(label + " *");
            return this;
        }

        public Field max(int length) {
            maxLength = length;
            if (input instanceof EditText) {
                ((EditText) input).setFilters(new InputFilter[]{new InputFilter.LengthFilter(length)});
            }
            return this;
        }

        public Field range(double lo, double hi) {
            min = lo;
            max = hi;
            return this;
        }

        public Field pattern(String regex, String message) {
            pattern = Pattern.compile(regex);
            patternMessage = message;
            return this;
        }

        public Field help(String text) {
            if (help == null) {
                help = Ui.text(ctx, text, 12, Ui.FAINT, false);
                help.setPadding(0, Ui.dp(ctx, 4), 0, 0);
                block.addView(help, block.getChildCount() - 1);
            } else {
                help.setText(text);
            }
            return this;
        }

        public Field alias(String... names) {
            aliases = names;
            return this;
        }

        public Field visible(boolean v) {
            block.setVisibility(v ? View.VISIBLE : View.GONE);
            return this;
        }

        public boolean isVisible() {
            return block.getVisibility() == View.VISIBLE;
        }

        public void setError(String msg) {
            if (msg == null || msg.length() == 0) {
                error.setVisibility(View.GONE);
            } else {
                error.setText(msg);
                error.setVisibility(View.VISIBLE);
            }
        }
    }

    private final Context ctx;
    private final LinearLayout root;
    private final Map<String, Field> fields = new LinkedHashMap<String, Field>();
    private Runnable onChange;

    public Form(Context c) {
        ctx = c;
        root = Ui.vbox(c);
    }

    public LinearLayout view() {
        return root;
    }

    public void onChange(Runnable r) {
        onChange = r;
    }

    private void changed() {
        if (onChange != null) {
            onChange.run();
        }
    }

    public Field field(String key) {
        return fields.get(key);
    }

    public boolean has(String key) {
        return fields.containsKey(key);
    }

    // ------------------------------------------------------------------ static input factory

    public static EditText input(Context c, String hint, int inputType) {
        EditText e = new EditText(c);
        e.setInputType(inputType);
        e.setHint(hint);
        e.setTextColor(Ui.INK);
        e.setHintTextColor(Ui.FAINT);
        e.setTypeface(Ui.font(c, false));
        e.setTextSize(15);
        e.setMinHeight(Ui.dp(c, 48));
        e.setPadding(Ui.dp(c, 14), Ui.dp(c, 11), Ui.dp(c, 14), Ui.dp(c, 11));
        e.setTextAlignment(View.TEXT_ALIGNMENT_VIEW_START);
        StateListDrawable bg = new StateListDrawable();
        GradientDrawable focused = Ui.outline(c, 0x24FFFFFF, Ui.ACCENT, 14);
        GradientDrawable normal = Ui.outline(c, Ui.FIELD, Ui.STROKE_STRONG, 14);
        bg.addState(new int[]{android.R.attr.state_focused}, focused);
        bg.addState(new int[]{}, normal);
        e.setBackground(bg);
        int cls = inputType & InputType.TYPE_MASK_CLASS;
        int variation = inputType & InputType.TYPE_MASK_VARIATION;
        if (cls == InputType.TYPE_CLASS_NUMBER || variation == InputType.TYPE_TEXT_VARIATION_URI
                || variation == InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS || variation == InputType.TYPE_TEXT_VARIATION_PASSWORD
                || variation == InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD) {
            ltr(e);
        }
        e.setLayoutParams(new LinearLayout.LayoutParams(Ui.MATCH, Ui.WRAP));
        return e;
    }

    /** Left-to-right content (URLs, e-mails, codes, numbers) inside the RTL screen. */
    public static void ltr(TextView t) {
        t.setTextDirection(View.TEXT_DIRECTION_LTR);
        t.setTextAlignment(View.TEXT_ALIGNMENT_TEXT_START);
    }

    // ------------------------------------------------------------------ layout pieces

    public Form section(String title) {
        TextView t = Ui.text(ctx, title, 15, Ui.ACCENT3, true);
        t.setPadding(0, Ui.dp(ctx, root.getChildCount() == 0 ? 0 : 14), 0, Ui.dp(ctx, 6));
        root.addView(t);
        return this;
    }

    public Form note(String text) {
        TextView t = Ui.text(ctx, text, 12.5f, Ui.MIST, false);
        t.setPadding(0, 0, 0, Ui.dp(ctx, 10));
        root.addView(t);
        return this;
    }

    public Form addView(View v) {
        root.addView(v);
        return this;
    }

    private LinearLayout block(String label) {
        LinearLayout b = Ui.vbox(ctx);
        b.setPadding(0, Ui.dp(ctx, 4), 0, Ui.dp(ctx, 10));
        TextView l = Ui.text(ctx, label, 13, Ui.MIST, true);
        l.setPadding(0, 0, 0, Ui.dp(ctx, 6));
        b.addView(l);
        root.addView(b, Ui.fill());
        return b;
    }

    private Field finish(Field f, View input) {
        f.input = input;
        f.block.addView(input);
        f.error = Ui.text(ctx, "", 12.5f, Ui.BAD, false);
        f.error.setPadding(0, Ui.dp(ctx, 4), 0, 0);
        f.error.setVisibility(View.GONE);
        f.block.addView(f.error);
        fields.put(f.key, f);
        return f;
    }

    // ------------------------------------------------------------------ field types

    public Field text(String key, String label, String value, String hint, int type) {
        Field f = new Field(key, label, type, block(label));
        int it;
        switch (type) {
            case MULTILINE:
                it = InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_MULTI_LINE | InputType.TYPE_TEXT_FLAG_CAP_SENTENCES;
                break;
            case INT:
                it = InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_FLAG_SIGNED;
                break;
            case DECIMAL:
                it = InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_FLAG_DECIMAL;
                break;
            case PASSWORD:
                it = InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD;
                break;
            case URL:
                it = InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI;
                break;
            case EMAIL:
                it = InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS;
                break;
            case DATE:
                it = InputType.TYPE_CLASS_DATETIME | InputType.TYPE_DATETIME_VARIATION_DATE;
                break;
            default:
                it = InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS;
        }
        EditText e = input(ctx, hint, it);
        if (type == MULTILINE) {
            e.setMinLines(3);
            e.setMaxLines(12);
            e.setGravity(Gravity.TOP | Gravity.START);
        }
        if (type == CODE || type == DATE) {
            ltr(e);
        }
        if (value != null) {
            e.setText(value);
        }
        e.addTextChangedListener(new TextWatcher() {
            @Override
            public void beforeTextChanged(CharSequence s, int start, int count, int after) {
            }

            @Override
            public void onTextChanged(CharSequence s, int start, int before, int count) {
            }

            @Override
            public void afterTextChanged(Editable s) {
                changed();
            }
        });
        return finish(f, e);
    }

    public Field text(String key, String label, String value) {
        return text(key, label, value, "", TEXT);
    }

    public Field color(String key, String label, String value) {
        final Field f = new Field(key, label, COLOR, block(label));
        LinearLayout row = Ui.hbox(ctx);
        final EditText e = input(ctx, "#8B7CFF", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS);
        ltr(e);
        final View swatch = new View(ctx);
        swatch.setBackground(Ui.outline(ctx, Ui.parseColor(value, Ui.ACCENT), Ui.STROKE_STRONG, 12));
        row.addView(e, Ui.weight(1));
        row.addView(Ui.hspace(ctx, 10));
        row.addView(swatch, new LinearLayout.LayoutParams(Ui.dp(ctx, 48), Ui.dp(ctx, 48)));
        if (value != null) {
            e.setText(value);
        }
        e.addTextChangedListener(new TextWatcher() {
            @Override
            public void beforeTextChanged(CharSequence s, int start, int count, int after) {
            }

            @Override
            public void onTextChanged(CharSequence s, int start, int before, int count) {
            }

            @Override
            public void afterTextChanged(Editable s) {
                swatch.setBackground(Ui.outline(ctx, Ui.parseColor(s.toString(), 0x00000000), Ui.STROKE_STRONG, 12));
                changed();
            }
        });
        f.pattern("^#?[0-9A-Fa-f]{6}$", "اكتب اللون بصيغة HEX مثل #8B7CFF");
        finish(f, row);
        f.input = e;
        return f;
    }

    /** Single choice opened in a dialog; allowEmpty adds a "not set" option. */
    public Field select(String key, String label, String[][] options, String value, boolean allowEmpty) {
        final Field f = new Field(key, label, SELECT, block(label));
        f.options = options;
        f.allowEmpty = allowEmpty;
        for (int i = 0; i < options.length; i++) {
            if (options[i][0].equals(value)) {
                f.selected = i;
            }
        }
        final TextView t = picker();
        f.input = t;
        refreshSelect(f);
        t.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                final List<String> labels = new ArrayList<String>();
                if (f.allowEmpty) {
                    labels.add("— غير محدد —");
                }
                for (String[] o : f.options) {
                    labels.add(o[1]);
                }
                int sel = f.allowEmpty ? f.selected + 1 : f.selected;
                Dialogs.choose(ctx, f.label, labels, Math.max(sel, f.allowEmpty ? 0 : -1), new Ui.IntCallback() {
                    @Override
                    public void done(int which) {
                        f.selected = f.allowEmpty ? which - 1 : which;
                        refreshSelect(f);
                        f.setError(null);
                        changed();
                    }
                });
            }
        });
        finish(f, t);
        return f;
    }

    /** Multiple choice (categories, languages). */
    public Field multi(String key, String label, String[][] options, List<String> values) {
        final Field f = new Field(key, label, MULTI, block(label));
        f.options = options;
        for (int i = 0; i < options.length; i++) {
            if (values != null && values.contains(options[i][0])) {
                f.multi.add(i);
            }
        }
        final TextView t = picker();
        f.input = t;
        refreshMulti(f);
        t.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                List<String> labels = new ArrayList<String>();
                for (String[] o : f.options) {
                    labels.add(o[1]);
                }
                Dialogs.multi(ctx, f.label, labels, f.multi, new Runnable() {
                    @Override
                    public void run() {
                        refreshMulti(f);
                        changed();
                    }
                });
            }
        });
        finish(f, t);
        return f;
    }

    public Field toggle(String key, String label, boolean value, String hint) {
        final Field f = new Field(key, label, SWITCH, block(label));
        ((TextView) f.block.getChildAt(0)).setVisibility(View.GONE);
        LinearLayout row = Ui.hbox(ctx);
        row.setBackground(Ui.outline(ctx, Ui.FIELD, Ui.STROKE, 14));
        row.setPadding(Ui.dp(ctx, 14), Ui.dp(ctx, 10), Ui.dp(ctx, 10), Ui.dp(ctx, 10));
        LinearLayout col = Ui.vbox(ctx);
        col.addView(Ui.text(ctx, label, 14, Ui.INK, true));
        if (hint != null && hint.length() > 0) {
            col.addView(Ui.text(ctx, hint, 12, Ui.MIST, false));
        }
        row.addView(col, Ui.weight(1));
        final Switch sw = new Switch(ctx);
        sw.setChecked(value);
        sw.setOnCheckedChangeListener(new android.widget.CompoundButton.OnCheckedChangeListener() {
            @Override
            public void onCheckedChanged(android.widget.CompoundButton b, boolean isChecked) {
                changed();
            }
        });
        row.addView(sw);
        row.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                sw.toggle();
            }
        });
        finish(f, row);
        f.input = sw;
        return f;
    }

    private TextView picker() {
        TextView t = Ui.text(ctx, "", 15, Ui.INK, false);
        t.setMinHeight(Ui.dp(ctx, 48));
        t.setGravity(Gravity.CENTER_VERTICAL | Gravity.START);
        t.setPadding(Ui.dp(ctx, 14), Ui.dp(ctx, 11), Ui.dp(ctx, 14), Ui.dp(ctx, 11));
        t.setBackground(Ui.ripple(ctx, Ui.outline(ctx, Ui.FIELD, Ui.STROKE_STRONG, 14), 14));
        android.graphics.drawable.Drawable chev = Ui.icon(ctx, R.drawable.ic_chev_down, Ui.MIST);
        int s = Ui.dp(ctx, 18);
        chev.setBounds(0, 0, s, s);
        // the app is always right-to-left: the "end" of the field is its left side
        t.setCompoundDrawables(chev, null, null, null);
        t.setCompoundDrawablePadding(Ui.dp(ctx, 8));
        return t;
    }

    private void refreshSelect(Field f) {
        TextView t = (TextView) f.input;
        if (f.selected >= 0 && f.selected < f.options.length) {
            t.setText(f.options[f.selected][1]);
            t.setTextColor(Ui.INK);
        } else {
            t.setText(f.allowEmpty ? "غير محدد" : "اختر…");
            t.setTextColor(Ui.FAINT);
        }
    }

    private void refreshMulti(Field f) {
        TextView t = (TextView) f.input;
        StringBuilder sb = new StringBuilder();
        for (Integer i : f.multi) {
            if (sb.length() > 0) {
                sb.append("، ");
            }
            sb.append(f.options[i][1]);
        }
        t.setText(sb.length() == 0 ? "لا شيء" : sb.toString());
        t.setTextColor(sb.length() == 0 ? Ui.FAINT : Ui.INK);
    }

    // ------------------------------------------------------------------ values

    public String str(String key) {
        Field f = fields.get(key);
        if (f == null) {
            return null;
        }
        switch (f.type) {
            case SELECT:
                return f.selected >= 0 ? f.options[f.selected][0] : null;
            case SWITCH:
                return ((Switch) f.input).isChecked() ? "1" : "0";
            case MULTI:
                return null;
            default:
                return ((EditText) f.input).getText().toString().trim();
        }
    }

    public boolean bool(String key) {
        Field f = fields.get(key);
        return f != null && f.type == SWITCH && ((Switch) f.input).isChecked();
    }

    public List<String> list(String key) {
        List<String> out = new ArrayList<String>();
        Field f = fields.get(key);
        if (f != null && f.type == MULTI) {
            for (Integer i : f.multi) {
                out.add(f.options[i][0]);
            }
        }
        return out;
    }

    public void set(String key, String value) {
        Field f = fields.get(key);
        if (f == null) {
            return;
        }
        if (f.type == SELECT) {
            f.selected = -1;
            for (int i = 0; i < f.options.length; i++) {
                if (f.options[i][0].equals(value)) {
                    f.selected = i;
                }
            }
            refreshSelect(f);
        } else if (f.type == SWITCH) {
            ((Switch) f.input).setChecked("1".equals(value) || "true".equals(value));
        } else if (f.input instanceof EditText) {
            ((EditText) f.input).setText(value == null ? "" : value);
        }
    }

    /** Typed values of the visible fields: empty → null, numbers as numbers, switches as booleans, multi as arrays. */
    public JSONObject values() {
        JSONObject o = new JSONObject();
        for (Field f : fields.values()) {
            if (!f.isVisible()) {
                continue;
            }
            switch (f.type) {
                case SWITCH:
                    Json.put(o, f.key, ((Switch) f.input).isChecked());
                    break;
                case MULTI:
                    JSONArray a = new JSONArray();
                    for (String v : list(f.key)) {
                        a.put(v);
                    }
                    Json.put(o, f.key, a);
                    break;
                case INT: {
                    String s = str(f.key);
                    if (s == null || s.length() == 0) {
                        Json.put(o, f.key, null);
                    } else {
                        try {
                            Json.put(o, f.key, Long.parseLong(s));
                        } catch (NumberFormatException e) {
                            Json.put(o, f.key, s);
                        }
                    }
                    break;
                }
                case DECIMAL: {
                    String s = str(f.key);
                    if (s == null || s.length() == 0) {
                        Json.put(o, f.key, null);
                    } else {
                        try {
                            Json.put(o, f.key, Double.parseDouble(s.replace(',', '.')));
                        } catch (NumberFormatException e) {
                            Json.put(o, f.key, s);
                        }
                    }
                    break;
                }
                case PASSWORD: {
                    String s = ((EditText) f.input).getText().toString();
                    if (s.length() > 0) {
                        Json.put(o, f.key, s);
                    }
                    break;
                }
                case COLOR: {
                    String s = str(f.key);
                    Json.put(o, f.key, s == null || s.length() == 0 ? null : (s.startsWith("#") ? s : "#" + s).toUpperCase(java.util.Locale.US));
                    break;
                }
                default: {
                    String s = str(f.key);
                    Json.put(o, f.key, s == null || s.length() == 0 ? null : s);
                }
            }
        }
        return o;
    }

    /** Client-side checks; marks the fields and returns false if something is wrong. */
    public boolean validate() {
        boolean ok = true;
        View firstBad = null;
        for (Field f : fields.values()) {
            f.setError(null);
            if (!f.isVisible()) {
                continue;
            }
            String err = null;
            String s = f.type == MULTI ? (f.multi.isEmpty() ? "" : "x") : str(f.key);
            boolean empty = s == null || s.length() == 0;
            if (f.required && empty && f.type != SWITCH) {
                err = f.type == SELECT ? "اختر " + f.label : f.label + " مطلوب";
            } else if (!empty && f.type != SELECT && f.type != MULTI && f.type != SWITCH) {
                if (f.maxLength > 0 && s.length() > f.maxLength) {
                    err = "الحد الأقصى " + f.maxLength + " حرفاً";
                } else if (f.pattern != null && !f.pattern.matcher(s).matches()) {
                    err = f.patternMessage;
                } else if (f.type == INT || f.type == DECIMAL) {
                    try {
                        double v = Double.parseDouble(s.replace(',', '.'));
                        if ((f.min != null && v < f.min) || (f.max != null && v > f.max)) {
                            err = "القيمة يجب أن تكون بين " + fmtNum(f.min) + " و " + fmtNum(f.max);
                        }
                    } catch (NumberFormatException e) {
                        err = "اكتب رقماً صحيحاً";
                    }
                } else if (f.type == EMAIL && !android.util.Patterns.EMAIL_ADDRESS.matcher(s).matches()) {
                    err = "بريد إلكتروني غير صحيح";
                } else if (f.type == URL && !(s.startsWith("http://") || s.startsWith("https://") || s.startsWith("/"))) {
                    err = "الرابط يجب أن يبدأ بـ https://";
                }
            }
            if (err != null) {
                f.setError(err);
                ok = false;
                if (firstBad == null) {
                    firstBad = f.block;
                }
            }
        }
        if (firstBad != null) {
            focus(firstBad);
        }
        return ok;
    }

    private static String fmtNum(Double d) {
        if (d == null) {
            return "";
        }
        return d == Math.floor(d) ? String.valueOf(d.longValue()) : String.valueOf(d);
    }

    /** Shows the server's per-field messages; unknown keys are returned so the caller can toast them. */
    public String showErrors(JSONObject serverFields) {
        StringBuilder unmatched = new StringBuilder();
        View firstBad = null;
        if (serverFields == null) {
            return "";
        }
        for (String k : Json.keys(serverFields)) {
            String msg = Json.str(serverFields, k);
            if (msg != null && msg.startsWith("[")) {
                JSONArray arr = Json.parseArray(msg);
                msg = arr.length() > 0 ? arr.optString(0) : msg;
            }
            Field target = fields.get(k);
            if (target == null) {
                for (Field f : fields.values()) {
                    for (String a : f.aliases) {
                        if (a.equals(k)) {
                            target = f;
                        }
                    }
                }
            }
            if (target != null && target.isVisible()) {
                target.setError(msg);
                if (firstBad == null) {
                    firstBad = target.block;
                }
            } else {
                if (unmatched.length() > 0) {
                    unmatched.append("\n");
                }
                unmatched.append(msg);
            }
        }
        if (firstBad != null) {
            focus(firstBad);
        }
        return unmatched.toString();
    }

    public void clearErrors() {
        for (Field f : fields.values()) {
            f.setError(null);
        }
    }

    private void focus(final View v) {
        v.post(new Runnable() {
            @Override
            public void run() {
                android.graphics.Rect r = new android.graphics.Rect(0, -Ui.dp(ctx, 24), v.getWidth(), v.getHeight());
                v.requestRectangleOnScreen(r, false);
            }
        });
    }

    /** Wraps a key/label list for select(): Form.opts("a","A","b","B"). */
    public static String[][] opts(String... kv) {
        String[][] out = new String[kv.length / 2][];
        for (int i = 0; i + 1 < kv.length; i += 2) {
            out[i / 2] = new String[]{kv[i], kv[i + 1]};
        }
        return out;
    }

    /** Options from a JSON list of objects: value key + label key. */
    public static String[][] opts(List<JSONObject> items, String valueKey, String labelKey) {
        String[][] out = new String[items.size()][];
        for (int i = 0; i < items.size(); i++) {
            JSONObject o = items.get(i);
            out[i] = new String[]{Json.str(o, valueKey, ""), Json.str(o, labelKey, "")};
        }
        return out;
    }

    /** Frame with the form inside a card (for full-screen forms). */
    public static FrameLayout.LayoutParams frame() {
        return new FrameLayout.LayoutParams(Ui.MATCH, Ui.WRAP);
    }
}
