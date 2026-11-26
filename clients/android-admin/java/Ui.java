package com.upsl.admin;

import android.app.Activity;
import android.content.Context;
import android.content.res.ColorStateList;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.Drawable;
import android.graphics.drawable.GradientDrawable;
import android.graphics.drawable.RippleDrawable;
import android.text.TextUtils;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.FrameLayout;
import android.widget.HorizontalScrollView;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import java.util.List;

/** Programmatic UI kit: the website's glass dark theme, Arabic RTL, Readex Pro. */
public final class Ui {
    private Ui() {
    }

    // palette (same values as the website's CSS variables)
    public static final int BG = 0xFF070816;
    public static final int BG2 = 0xFF12113A;
    public static final int CARD = 0xD9151A3A;
    public static final int CARD_SOLID = 0xFF151A3A;
    public static final int FIELD = 0x1FFFFFFF;
    public static final int STROKE = 0x29B0BAFF;
    public static final int STROKE_STRONG = 0x52B0BAFF;
    public static final int INK = 0xFFE8EBFF;
    public static final int MIST = 0xFF9AA3C7;
    public static final int FAINT = 0xFF6B7399;
    public static final int ACCENT = 0xFF8B7CFF;
    public static final int ACCENT2 = 0xFF5AA9FF;
    public static final int ACCENT3 = 0xFF5EE6F0;
    public static final int OK = 0xFF4ADE80;
    public static final int WARN = 0xFFFBBF24;
    public static final int BAD = 0xFFFB7185;
    public static final int[] GRADIENT = {ACCENT, ACCENT2, ACCENT3};

    public static final int MATCH = ViewGroup.LayoutParams.MATCH_PARENT;
    public static final int WRAP = ViewGroup.LayoutParams.WRAP_CONTENT;

    public static final int BTN_PRIMARY = 0;
    public static final int BTN_SECONDARY = 1;
    public static final int BTN_DANGER = 2;
    public static final int BTN_GHOST = 3;

    private static Typeface regular;
    private static Typeface semibold;

    // ------------------------------------------------------------------ basics

    public static int dp(Context c, float v) {
        return Math.round(TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v, c.getResources().getDisplayMetrics()));
    }

    public static Typeface font(Context c, boolean bold) {
        if (regular == null) {
            try {
                regular = Typeface.createFromAsset(c.getAssets(), "fonts/readex_regular.ttf");
                semibold = Typeface.createFromAsset(c.getAssets(), "fonts/readex_semibold.ttf");
            } catch (RuntimeException e) {
                regular = Typeface.DEFAULT;
                semibold = Typeface.DEFAULT_BOLD;
            }
        }
        return bold ? semibold : regular;
    }

    public static int alpha(int color, float a) {
        return (Math.round(a * 255) << 24) | (color & 0x00FFFFFF);
    }

    public static int parseColor(String hex, int fallback) {
        if (hex == null || hex.length() == 0) {
            return fallback;
        }
        try {
            return Color.parseColor(hex.startsWith("#") ? hex : "#" + hex);
        } catch (IllegalArgumentException e) {
            return fallback;
        }
    }

    public static void toast(Context c, String msg) {
        if (msg != null && msg.length() > 0) {
            Toast.makeText(c, msg, msg.length() > 60 ? Toast.LENGTH_LONG : Toast.LENGTH_SHORT).show();
        }
    }

    /** Forces right-to-left layout for the whole screen (the UI is Arabic whatever the device language). */
    public static void rtl(Activity a) {
        a.getWindow().getDecorView().setLayoutDirection(View.LAYOUT_DIRECTION_RTL);
        GradientDrawable bg = new GradientDrawable(GradientDrawable.Orientation.TL_BR, new int[]{0xFF17154A, 0xFF0B0C24, BG});
        a.getWindow().setBackgroundDrawable(bg);
        a.getWindow().setStatusBarColor(0xFF110F38);
        a.getWindow().setNavigationBarColor(BG);
    }

    // ------------------------------------------------------------------ drawables

    public static GradientDrawable rounded(int fill, float radiusDp, Context c) {
        GradientDrawable d = new GradientDrawable();
        d.setColor(fill);
        d.setCornerRadius(dp(c, radiusDp));
        return d;
    }

    public static GradientDrawable glass(Context c, float radiusDp) {
        GradientDrawable d = rounded(CARD, radiusDp, c);
        d.setStroke(Math.max(1, dp(c, 1)), STROKE);
        return d;
    }

    public static GradientDrawable outline(Context c, int fill, int stroke, float radiusDp) {
        GradientDrawable d = rounded(fill, radiusDp, c);
        d.setStroke(Math.max(1, dp(c, 1)), stroke);
        return d;
    }

    public static GradientDrawable gradient(Context c, float radiusDp) {
        GradientDrawable d = new GradientDrawable(GradientDrawable.Orientation.RIGHT_LEFT, GRADIENT);
        d.setCornerRadius(dp(c, radiusDp));
        return d;
    }

    public static Drawable ripple(Context c, Drawable content, float radiusDp) {
        GradientDrawable mask = rounded(Color.WHITE, radiusDp, c);
        return new RippleDrawable(ColorStateList.valueOf(0x40FFFFFF), content, mask);
    }

    public static Drawable icon(Context c, int res, int color) {
        Drawable d = c.getResources().getDrawable(res, null).mutate();
        d.setTint(color);
        return d;
    }

    // ------------------------------------------------------------------ layout helpers

    public static LinearLayout vbox(Context c) {
        LinearLayout l = new LinearLayout(c);
        l.setOrientation(LinearLayout.VERTICAL);
        return l;
    }

    public static LinearLayout hbox(Context c) {
        LinearLayout l = new LinearLayout(c);
        l.setOrientation(LinearLayout.HORIZONTAL);
        l.setGravity(Gravity.CENTER_VERTICAL);
        return l;
    }

    public static LinearLayout.LayoutParams lp(int w, int h) {
        return new LinearLayout.LayoutParams(w, h);
    }

    public static LinearLayout.LayoutParams weight(float w) {
        return new LinearLayout.LayoutParams(0, WRAP, w);
    }

    public static LinearLayout.LayoutParams margins(Context c, int w, int h, float top, float bottom) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(w, h);
        p.topMargin = dp(c, top);
        p.bottomMargin = dp(c, bottom);
        return p;
    }

    public static View space(Context c, float hDp) {
        View v = new View(c);
        v.setLayoutParams(new LinearLayout.LayoutParams(1, dp(c, hDp)));
        return v;
    }

    public static View hspace(Context c, float wDp) {
        View v = new View(c);
        v.setLayoutParams(new LinearLayout.LayoutParams(dp(c, wDp), 1));
        return v;
    }

    public static View flex(Context c) {
        View v = new View(c);
        v.setLayoutParams(new LinearLayout.LayoutParams(0, 1, 1f));
        return v;
    }

    public static View divider(Context c) {
        View v = new View(c);
        v.setBackgroundColor(STROKE);
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(MATCH, Math.max(1, dp(c, 1)));
        p.topMargin = dp(c, 10);
        p.bottomMargin = dp(c, 10);
        v.setLayoutParams(p);
        return v;
    }

    public static void pad(View v, float dp) {
        int p = dp(v.getContext(), dp);
        v.setPadding(p, p, p, p);
    }

    public static void pad(View v, float h, float vert) {
        int ph = dp(v.getContext(), h);
        int pv = dp(v.getContext(), vert);
        v.setPadding(ph, pv, ph, pv);
    }

    // ------------------------------------------------------------------ text

    public static TextView text(Context c, CharSequence s, float sp, int color, boolean bold) {
        TextView t = new TextView(c);
        t.setText(s);
        t.setTextSize(TypedValue.COMPLEX_UNIT_SP, sp);
        t.setTextColor(color);
        t.setTypeface(font(c, bold));
        t.setTextAlignment(View.TEXT_ALIGNMENT_VIEW_START);
        t.setLineSpacing(0, 1.12f);
        return t;
    }

    public static TextView h1(Context c, CharSequence s) {
        return text(c, s, 22, INK, true);
    }

    public static TextView h2(Context c, CharSequence s) {
        TextView t = text(c, s, 16, INK, true);
        t.setPadding(0, dp(c, 4), 0, dp(c, 8));
        return t;
    }

    public static TextView body(Context c, CharSequence s) {
        return text(c, s, 14, INK, false);
    }

    public static TextView muted(Context c, CharSequence s) {
        return text(c, s, 13, MIST, false);
    }

    public static TextView small(Context c, CharSequence s) {
        return text(c, s, 12, FAINT, false);
    }

    /** Left-to-right text (file names, handles, hosts) still aligned to the right edge of the RTL screen. */
    public static TextView ltrText(TextView t) {
        t.setTextDirection(View.TEXT_DIRECTION_LTR);
        t.setTextAlignment(View.TEXT_ALIGNMENT_VIEW_START);
        return t;
    }

    public static TextView single(TextView t) {
        t.setSingleLine(true);
        t.setEllipsize(TextUtils.TruncateAt.END);
        return t;
    }

    /** Monospace LTR text (hashes, paths, codes). */
    public static TextView mono(Context c, CharSequence s, float sp, int color) {
        TextView t = text(c, s, sp, color, false);
        t.setTypeface(Typeface.MONOSPACE);
        t.setTextDirection(View.TEXT_DIRECTION_LTR);
        t.setTextIsSelectable(true);
        return t;
    }

    // ------------------------------------------------------------------ components

    public static LinearLayout card(Context c) {
        LinearLayout l = vbox(c);
        l.setBackground(glass(c, 18));
        pad(l, 16);
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(MATCH, WRAP);
        p.bottomMargin = dp(c, 12);
        l.setLayoutParams(p);
        return l;
    }

    public static ImageView image(Context c, int res, float sizeDp, int color) {
        ImageView iv = new ImageView(c);
        iv.setImageDrawable(icon(c, res, color));
        int s = dp(c, sizeDp);
        iv.setLayoutParams(new LinearLayout.LayoutParams(s, s));
        return iv;
    }

    /** Round tinted icon "plate" used in rows and tiles. */
    public static FrameLayout iconPlate(Context c, int res, int color, float sizeDp) {
        FrameLayout f = new FrameLayout(c);
        f.setBackground(rounded(alpha(color, 0.16f), sizeDp / 3.2f, c));
        int s = dp(c, sizeDp);
        f.setLayoutParams(new LinearLayout.LayoutParams(s, s));
        ImageView iv = new ImageView(c);
        iv.setImageDrawable(icon(c, res, color));
        int is = dp(c, sizeDp * 0.5f);
        f.addView(iv, new FrameLayout.LayoutParams(is, is, Gravity.CENTER));
        return f;
    }

    public static TextView button(Context c, String label, int iconRes, int style, View.OnClickListener click) {
        TextView b = text(c, label, 14, INK, true);
        b.setGravity(Gravity.CENTER);
        b.setTextAlignment(View.TEXT_ALIGNMENT_GRAVITY);
        b.setSingleLine(true);
        b.setMinHeight(dp(c, 44));
        b.setMinimumHeight(dp(c, 44));
        pad(b, 16, 10);
        int fg = INK;
        Drawable bg;
        switch (style) {
            case BTN_PRIMARY:
                bg = gradient(c, 14);
                fg = 0xFF0B0C24;
                break;
            case BTN_DANGER:
                bg = outline(c, alpha(BAD, 0.14f), alpha(BAD, 0.55f), 14);
                fg = BAD;
                break;
            case BTN_GHOST:
                bg = rounded(0x00000000, 14, c);
                fg = MIST;
                break;
            default:
                bg = outline(c, FIELD, STROKE_STRONG, 14);
        }
        b.setTextColor(fg);
        b.setBackground(ripple(c, bg, 14));
        if (iconRes != 0) {
            b.setText(withIcon(c, icon(c, iconRes, fg), label, 18));
        }
        b.setOnClickListener(click);
        b.setClickable(true);
        b.setFocusable(true);
        return b;
    }

    /**
     * Icon + label as one piece of text, so the pair stays centred and the icon sits at the start
     * of the (right-to-left) line whatever the device language is.
     */
    public static CharSequence withIcon(Context c, Drawable d, String label, float sizeDp) {
        int s = dp(c, sizeDp);
        d.setBounds(0, 0, s, s);
        android.text.SpannableString sp = new android.text.SpannableString("\uFFFC  " + label);
        sp.setSpan(new CenteredIcon(d), 0, 1, android.text.Spanned.SPAN_EXCLUSIVE_EXCLUSIVE);
        return sp;
    }

    /** Image span centred on the text line (ImageSpan.ALIGN_CENTER needs API 29). */
    public static final class CenteredIcon extends android.text.style.ReplacementSpan {
        private final Drawable d;

        public CenteredIcon(Drawable d) {
            this.d = d;
        }

        @Override
        public int getSize(android.graphics.Paint paint, CharSequence text, int start, int end, android.graphics.Paint.FontMetricsInt fm) {
            if (fm != null) {
                android.graphics.Paint.FontMetricsInt pfm = paint.getFontMetricsInt();
                fm.ascent = pfm.ascent;
                fm.descent = pfm.descent;
                fm.top = pfm.top;
                fm.bottom = pfm.bottom;
            }
            return d.getBounds().right;
        }

        @Override
        public void draw(android.graphics.Canvas canvas, CharSequence text, int start, int end, float x, int top, int y, int bottom, android.graphics.Paint paint) {
            android.graphics.Paint.FontMetricsInt fm = paint.getFontMetricsInt();
            int lineCenter = y + (fm.descent + fm.ascent) / 2;
            canvas.save();
            canvas.translate(x, lineCenter - d.getBounds().bottom / 2f);
            d.draw(canvas);
            canvas.restore();
        }
    }

    public static ImageView iconButton(Context c, int res, String description, View.OnClickListener click) {
        ImageView iv = new ImageView(c);
        iv.setImageDrawable(icon(c, res, INK));
        int p = dp(c, 10);
        iv.setPadding(p, p, p, p);
        iv.setBackground(ripple(c, rounded(0x00000000, 22, c), 22));
        iv.setContentDescription(description);
        iv.setOnClickListener(click);
        int s = dp(c, 44);
        iv.setLayoutParams(new LinearLayout.LayoutParams(s, s));
        return iv;
    }

    /** Small status pill. */
    public static TextView chip(Context c, String label, int color) {
        TextView t = text(c, label, 11, color, true);
        t.setTextAlignment(View.TEXT_ALIGNMENT_GRAVITY);
        t.setGravity(Gravity.CENTER);
        t.setSingleLine(true);
        t.setBackground(outline(c, alpha(color, 0.14f), alpha(color, 0.38f), 999));
        pad(t, 9, 3);
        return t;
    }

    /** Selectable filter chip; selected chips use the accent gradient. */
    public static TextView pill(Context c, String label, boolean selected, View.OnClickListener click) {
        TextView t = text(c, label, 13, selected ? 0xFF0B0C24 : INK, true);
        t.setTextAlignment(View.TEXT_ALIGNMENT_GRAVITY);
        t.setGravity(Gravity.CENTER);
        t.setSingleLine(true);
        t.setBackground(ripple(c, selected ? gradient(c, 999) : outline(c, FIELD, STROKE, 999), 999));
        pad(t, 14, 7);
        t.setOnClickListener(click);
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(WRAP, WRAP);
        p.setMarginEnd(dp(c, 8));
        t.setLayoutParams(p);
        return t;
    }

    /** Horizontally scrolling row of pills (tabs / filters). */
    public static HorizontalScrollView pillRow(Context c, List<String> labels, int selected, final IntCallback cb) {
        HorizontalScrollView hs = new HorizontalScrollView(c);
        hs.setHorizontalScrollBarEnabled(false);
        LinearLayout row = hbox(c);
        row.setPadding(0, dp(c, 2), 0, dp(c, 10));
        for (int i = 0; i < labels.size(); i++) {
            final int idx = i;
            row.addView(pill(c, labels.get(i), i == selected, new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    cb.done(idx);
                }
            }));
        }
        hs.addView(row);
        return hs;
    }

    public interface IntCallback {
        void done(int value);
    }

    public interface Callback<T> {
        void done(T value);
    }

    /** List row: optional leading view, title + subtitle, optional trailing view. */
    public static LinearLayout row(Context c, View leading, CharSequence title, CharSequence subtitle, View trailing) {
        LinearLayout r = hbox(c);
        r.setPadding(dp(c, 14), dp(c, 12), dp(c, 14), dp(c, 12));
        r.setBackground(ripple(c, glass(c, 16), 16));
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(MATCH, WRAP);
        p.bottomMargin = dp(c, 8);
        r.setLayoutParams(p);
        if (leading != null) {
            r.addView(leading);
            r.addView(hspace(c, 12));
        }
        LinearLayout col = vbox(c);
        TextView t = text(c, title, 15, INK, true);
        t.setMaxLines(2);
        t.setEllipsize(TextUtils.TruncateAt.END);
        col.addView(t);
        if (subtitle != null && subtitle.length() > 0) {
            TextView s = text(c, subtitle, 12.5f, MIST, false);
            s.setMaxLines(3);
            s.setEllipsize(TextUtils.TruncateAt.END);
            s.setPadding(0, dp(c, 2), 0, 0);
            col.addView(s);
        }
        r.addView(col, weight(1));
        if (trailing != null) {
            r.addView(hspace(c, 8));
            r.addView(trailing);
        }
        return r;
    }

    /** Label/value line used in detail cards. */
    public static LinearLayout kv(Context c, String label, CharSequence value) {
        LinearLayout r = hbox(c);
        r.setPadding(0, dp(c, 5), 0, dp(c, 5));
        r.setGravity(Gravity.TOP);
        TextView l = text(c, label, 13, MIST, false);
        r.addView(l, new LinearLayout.LayoutParams(dp(c, 118), WRAP));
        TextView v = text(c, value == null || value.length() == 0 ? "—" : value, 13.5f, INK, false);
        v.setTextIsSelectable(true);
        r.addView(v, weight(1));
        return r;
    }

    /** Stat tile: big number + label (+ optional hint). */
    public static LinearLayout stat(Context c, int iconRes, int color, String value, String label, String hint) {
        LinearLayout t = vbox(c);
        t.setBackground(glass(c, 18));
        pad(t, 14);
        LinearLayout top = hbox(c);
        top.addView(iconPlate(c, iconRes, color, 34));
        top.addView(flex(c));
        t.addView(top);
        t.addView(space(c, 10));
        TextView v = text(c, value, 24, INK, true);
        v.setSingleLine(true);
        t.addView(v);
        t.addView(single(text(c, label, 12.5f, MIST, false)));
        if (hint != null && hint.length() > 0) {
            TextView h = text(c, hint, 11.5f, color, false);
            h.setSingleLine(true);
            h.setPadding(0, dp(c, 3), 0, 0);
            t.addView(h);
        }
        return t;
    }

    /** Two-column grid built from rows of LinearLayouts (no GridLayout quirks with RTL). */
    public static LinearLayout grid(Context c, List<View> cells, int columns) {
        LinearLayout g = vbox(c);
        LinearLayout row = null;
        for (int i = 0; i < cells.size(); i++) {
            if (i % columns == 0) {
                row = hbox(c);
                row.setGravity(Gravity.TOP);
                LinearLayout.LayoutParams rp = new LinearLayout.LayoutParams(MATCH, WRAP);
                rp.bottomMargin = dp(c, 10);
                g.addView(row, rp);
            }
            LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(0, MATCH, 1f);
            if (i % columns != columns - 1) {
                p.setMarginEnd(dp(c, 10));
            }
            row.addView(cells.get(i), p);
        }
        if (row != null) {
            int rest = (columns - cells.size() % columns) % columns;
            for (int i = 0; i < rest; i++) {
                View v = new View(c);
                LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(0, 1, 1f);
                if (i < rest - 1) {
                    p.setMarginEnd(dp(c, 10));
                }
                row.addView(v, p);
            }
        }
        return g;
    }

    public static ProgressBar spinner(Context c) {
        ProgressBar pb = new ProgressBar(c);
        pb.setIndeterminateTintList(ColorStateList.valueOf(ACCENT));
        int s = dp(c, 40);
        pb.setLayoutParams(new LinearLayout.LayoutParams(s, s));
        return pb;
    }

    /** Horizontal progress bar in the accent colours. */
    public static ProgressBar progress(Context c) {
        ProgressBar pb = new ProgressBar(c, null, android.R.attr.progressBarStyleHorizontal);
        pb.setMax(1000);
        pb.setProgressTintList(ColorStateList.valueOf(ACCENT2));
        pb.setProgressBackgroundTintList(ColorStateList.valueOf(0x33B0BAFF));
        pb.setLayoutParams(new LinearLayout.LayoutParams(MATCH, dp(c, 6)));
        return pb;
    }

    /** Meter line: label, value and a thin bar (disk usage etc.). */
    public static LinearLayout meter(Context c, String label, String value, float fraction, int color) {
        LinearLayout box = vbox(c);
        box.setPadding(0, dp(c, 6), 0, dp(c, 6));
        LinearLayout top = hbox(c);
        top.addView(text(c, label, 13, INK, false), weight(1));
        TextView v = text(c, value, 12.5f, MIST, false);
        v.setTextDirection(View.TEXT_DIRECTION_ANY_RTL);
        top.addView(v);
        box.addView(top);
        box.addView(space(c, 6));
        box.addView(new Bar(c, Math.max(0f, Math.min(1f, fraction)), color));
        return box;
    }

    /** Thin rounded bar used by meters and charts. */
    public static final class Bar extends View {
        private final float fraction;
        private final int color;
        private final android.graphics.Paint paint = new android.graphics.Paint(android.graphics.Paint.ANTI_ALIAS_FLAG);
        private final android.graphics.RectF rect = new android.graphics.RectF();

        public Bar(Context c, float fraction, int color) {
            super(c);
            this.fraction = fraction;
            this.color = color;
            setLayoutParams(new LinearLayout.LayoutParams(MATCH, dp(c, 6)));
        }

        @Override
        protected void onDraw(android.graphics.Canvas canvas) {
            float h = getHeight();
            float w = getWidth();
            float r = h / 2f;
            paint.setColor(0x26B0BAFF);
            rect.set(0, 0, w, h);
            canvas.drawRoundRect(rect, r, r, paint);
            paint.setColor(color);
            float fw = Math.max(fraction > 0 ? h : 0, w * fraction);
            boolean rtl = getLayoutDirection() == View.LAYOUT_DIRECTION_RTL;
            if (rtl) {
                rect.set(w - fw, 0, w, h);
            } else {
                rect.set(0, 0, fw, h);
            }
            canvas.drawRoundRect(rect, r, r, paint);
        }
    }

    /** Empty / error state with an optional action. */
    public static LinearLayout state(Context c, int iconRes, int color, String title, String body, String action, View.OnClickListener click) {
        LinearLayout box = vbox(c);
        box.setGravity(Gravity.CENTER_HORIZONTAL);
        box.setPadding(dp(c, 24), dp(c, 40), dp(c, 24), dp(c, 40));
        box.addView(iconPlate(c, iconRes, color, 60));
        box.addView(space(c, 14));
        TextView t = text(c, title, 16, INK, true);
        t.setGravity(Gravity.CENTER);
        t.setTextAlignment(View.TEXT_ALIGNMENT_CENTER);
        box.addView(t);
        if (body != null && body.length() > 0) {
            TextView b = text(c, body, 13.5f, MIST, false);
            b.setTextAlignment(View.TEXT_ALIGNMENT_CENTER);
            b.setPadding(0, dp(c, 6), 0, 0);
            box.addView(b);
        }
        if (action != null && click != null) {
            box.addView(space(c, 16));
            box.addView(button(c, action, 0, BTN_SECONDARY, click), new LinearLayout.LayoutParams(WRAP, WRAP));
        }
        return box;
    }

    public static LinearLayout.LayoutParams fill() {
        return new LinearLayout.LayoutParams(MATCH, WRAP);
    }

    /** Rounded remote image (cover, screenshot, logo) with a placeholder background. */
    public static ImageView remote(Context c, String url, float wDp, float hDp, float radiusDp) {
        ImageView iv = new ImageView(c);
        iv.setScaleType(ImageView.ScaleType.CENTER_CROP);
        iv.setBackground(outline(c, 0x14FFFFFF, STROKE, radiusDp));
        iv.setClipToOutline(true);
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(wDp <= 0 ? MATCH : dp(c, wDp), dp(c, hDp));
        iv.setLayoutParams(p);
        ImageLoader.get(c).load(url, iv, dp(c, Math.max(wDp, hDp)));
        return iv;
    }
}
