package com.upsl.admin;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;
import java.util.TimeZone;

/** Formatting helpers for sizes, dates and Arabic counts. */
public final class Fmt {
    private Fmt() {
    }

    public static String bytes(long b) {
        if (b < 0) {
            return "—";
        }
        String[] u = {"B", "KB", "MB", "GB", "TB"};
        double v = b;
        int i = 0;
        while (v >= 1024 && i < u.length - 1) {
            v /= 1024;
            i++;
        }
        // the left-to-right mark keeps "6.0 MB" in that order inside Arabic sentences
        return "\u200E" + (i == 0 ? String.valueOf((long) v) : String.format(Locale.US, "%.1f", v)) + " " + u[i];
    }

    /** Parses the API's ISO-8601 dates ("2026-11-25T16:29:07+00:00"); works on Android 5 (no "X" pattern there). */
    public static Date parseIso(String iso) {
        if (iso == null || iso.length() < 19) {
            return null;
        }
        try {
            String s = iso.trim();
            if (s.endsWith("Z")) {
                s = s.substring(0, s.length() - 1) + "+0000";
            } else if (s.length() > 19 && (s.charAt(s.length() - 6) == '+' || s.charAt(s.length() - 6) == '-') && s.charAt(s.length() - 3) == ':') {
                s = s.substring(0, s.length() - 3) + s.substring(s.length() - 2);
            } else if (s.length() == 19) {
                s = s + "+0000";
            }
            SimpleDateFormat f = new SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssZ", Locale.US);
            return f.parse(s.replace(' ', 'T'));
        } catch (Exception e) {
            return null;
        }
    }

    public static String date(String iso) {
        Date d = parseIso(iso);
        if (d == null) {
            return iso == null ? "—" : iso;
        }
        SimpleDateFormat f = new SimpleDateFormat("yyyy/MM/dd HH:mm", Locale.US);
        f.setTimeZone(TimeZone.getDefault());
        return f.format(d);
    }

    public static String dateOnly(String iso) {
        Date d = parseIso(iso);
        if (d == null) {
            return iso == null ? "—" : iso;
        }
        SimpleDateFormat f = new SimpleDateFormat("yyyy/MM/dd", Locale.US);
        f.setTimeZone(TimeZone.getDefault());
        return f.format(d);
    }

    public static String ago(String iso) {
        Date d = parseIso(iso);
        if (d == null) {
            return "—";
        }
        long s = (System.currentTimeMillis() - d.getTime()) / 1000;
        if (s < 0) {
            return date(iso);
        }
        if (s < 60) {
            return "الآن";
        }
        if (s < 3600) {
            return "منذ " + count(s / 60, "دقيقة", "دقيقتين", "دقائق");
        }
        if (s < 86400) {
            return "منذ " + count(s / 3600, "ساعة", "ساعتين", "ساعات");
        }
        if (s < 86400 * 30) {
            return "منذ " + count(s / 86400, "يوم", "يومين", "أيام");
        }
        return dateOnly(iso);
    }

    /** "دقيقة" / "دقيقتين" / "5 دقائق" / "11 دقيقة". */
    public static String count(long n, String one, String two, String few) {
        if (n == 1) {
            return one;
        }
        if (n == 2) {
            return two;
        }
        long m = n % 100;
        return n + " " + (m >= 3 && m <= 10 ? few : one);
    }

    /** Noun after a numeral: 3–10 take the plural. */
    public static String noun(long n, String singular, String plural) {
        long m = Math.abs(n) % 100;
        return (m >= 3 && m <= 10) ? plural : singular;
    }

    public static String duration(double seconds) {
        if (Double.isNaN(seconds) || Double.isInfinite(seconds) || seconds <= 0) {
            return "—";
        }
        long s = Math.round(seconds);
        long h = s / 3600;
        long m = (s % 3600) / 60;
        if (h > 0) {
            return h + "س " + m + "د";
        }
        return m > 0 ? m + "د " + (s % 60) + "ث" : (s % 60) + "ث";
    }

    /** Wraps Latin text/numbers so they keep their order inside Arabic sentences. */
    public static String ltr(String s) {
        return "⁦" + (s == null ? "" : s) + "⁩";
    }

    /** 12,345 (Latin digits with separators, like the website). */
    public static String num(long n) {
        return String.format(Locale.US, "%,d", n);
    }

    public static boolean empty(String s) {
        return s == null || s.trim().length() == 0;
    }
}
