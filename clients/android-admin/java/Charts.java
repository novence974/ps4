package com.upsl.admin;

import android.content.Context;
import android.graphics.Canvas;
import android.graphics.LinearGradient;
import android.graphics.Paint;
import android.graphics.Path;
import android.graphics.Shader;
import android.view.MotionEvent;
import android.view.View;
import android.widget.LinearLayout;

/** Tiny charts drawn on a Canvas (no chart library: the app uses the framework only). */
public final class Charts {
    private Charts() {
    }

    public interface Label {
        String at(int index);
    }

    /**
     * Area chart for a daily series (e.g. downloads per day for 30 days). Touching the chart shows the value
     * of the day under the finger. Time runs right-to-left to match the Arabic reading direction.
     */
    public static final class Area extends View {
        private final long[] values;
        private final Label label;
        private final Paint line = new Paint(Paint.ANTI_ALIAS_FLAG);
        private final Paint fill = new Paint(Paint.ANTI_ALIAS_FLAG);
        private final Paint grid = new Paint(Paint.ANTI_ALIAS_FLAG);
        private final Paint text = new Paint(Paint.ANTI_ALIAS_FLAG);
        private final Paint dot = new Paint(Paint.ANTI_ALIAS_FLAG);
        private int touched = -1;

        public Area(Context c, long[] values, Label label) {
            super(c);
            this.values = values;
            this.label = label;
            line.setStyle(Paint.Style.STROKE);
            line.setStrokeWidth(Ui.dp(c, 2.2f));
            line.setStrokeJoin(Paint.Join.ROUND);
            line.setStrokeCap(Paint.Cap.ROUND);
            grid.setColor(0x1FB0BAFF);
            grid.setStrokeWidth(Math.max(1, Ui.dp(c, 1)));
            text.setColor(Ui.MIST);
            text.setTextSize(Ui.dp(c, 11));
            text.setTypeface(Ui.font(c, false));
            dot.setColor(Ui.ACCENT3);
            setLayoutParams(new LinearLayout.LayoutParams(Ui.MATCH, Ui.dp(c, 150)));
        }

        private long max() {
            long m = 1;
            for (long v : values) {
                m = Math.max(m, v);
            }
            return m;
        }

        private float x(int i, float w, float pad) {
            if (values.length < 2) {
                return w / 2;
            }
            float t = i / (float) (values.length - 1);
            return w - pad - t * (w - 2 * pad);
        }

        @Override
        protected void onDraw(Canvas canvas) {
            float w = getWidth();
            float h = getHeight();
            float pad = Ui.dp(getContext(), 6);
            float top = Ui.dp(getContext(), 22);
            float bottom = h - Ui.dp(getContext(), 6);
            long max = max();
            for (int g = 0; g <= 2; g++) {
                float gy = top + (bottom - top) * g / 2f;
                canvas.drawLine(0, gy, w, gy, grid);
            }
            if (values.length == 0) {
                return;
            }
            Path p = new Path();
            Path area = new Path();
            for (int i = 0; i < values.length; i++) {
                float px = x(i, w, pad);
                float py = bottom - (bottom - top) * values[i] / (float) max;
                if (i == 0) {
                    p.moveTo(px, py);
                    area.moveTo(px, bottom);
                    area.lineTo(px, py);
                } else {
                    p.lineTo(px, py);
                    area.lineTo(px, py);
                }
            }
            area.lineTo(x(values.length - 1, w, pad), bottom);
            area.close();
            fill.setShader(new LinearGradient(0, top, 0, bottom, Ui.alpha(Ui.ACCENT2, 0.35f), Ui.alpha(Ui.ACCENT2, 0f), Shader.TileMode.CLAMP));
            canvas.drawPath(area, fill);
            line.setShader(new LinearGradient(w, 0, 0, 0, Ui.ACCENT, Ui.ACCENT3, Shader.TileMode.CLAMP));
            canvas.drawPath(p, line);
            String caption = "الأعلى: " + Fmt.num(max);
            int sel = touched >= 0 && touched < values.length ? touched : -1;
            if (sel >= 0) {
                float px = x(sel, w, pad);
                float py = bottom - (bottom - top) * values[sel] / (float) max;
                canvas.drawLine(px, top, px, bottom, grid);
                canvas.drawCircle(px, py, Ui.dp(getContext(), 4), dot);
                caption = (label != null ? label.at(sel) + ": " : "") + Fmt.num(values[sel]);
            }
            text.setTextAlign(Paint.Align.RIGHT);
            canvas.drawText(caption, w - pad, Ui.dp(getContext(), 13), text);
        }

        @Override
        public boolean onTouchEvent(MotionEvent e) {
            if (values.length == 0) {
                return false;
            }
            if (e.getAction() == MotionEvent.ACTION_UP || e.getAction() == MotionEvent.ACTION_CANCEL) {
                touched = -1;
                invalidate();
                return true;
            }
            float w = getWidth();
            float pad = Ui.dp(getContext(), 6);
            float t = (w - pad - e.getX()) / Math.max(1f, w - 2 * pad);
            touched = Math.max(0, Math.min(values.length - 1, Math.round(t * (values.length - 1))));
            getParent().requestDisallowInterceptTouchEvent(true);
            invalidate();
            return true;
        }
    }
}
