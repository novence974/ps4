package com.upsl.admin;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.Iterator;
import java.util.List;

/** Null-safe JSON helpers (org.json returns "null" strings for JSONObject.NULL, which we never want on screen). */
public final class Json {
    private Json() {
    }

    public static String str(JSONObject o, String key) {
        if (o == null || !o.has(key) || o.isNull(key)) {
            return null;
        }
        Object v = o.opt(key);
        return v == null ? null : String.valueOf(v);
    }

    public static String str(JSONObject o, String key, String fallback) {
        String s = str(o, key);
        return s == null || s.length() == 0 ? fallback : s;
    }

    public static int num(JSONObject o, String key, int fallback) {
        if (o == null || !o.has(key) || o.isNull(key)) {
            return fallback;
        }
        Object v = o.opt(key);
        if (v instanceof Number) {
            return ((Number) v).intValue();
        }
        try {
            return (int) Double.parseDouble(String.valueOf(v));
        } catch (NumberFormatException e) {
            return fallback;
        }
    }

    public static long lng(JSONObject o, String key, long fallback) {
        if (o == null || !o.has(key) || o.isNull(key)) {
            return fallback;
        }
        Object v = o.opt(key);
        if (v instanceof Number) {
            return ((Number) v).longValue();
        }
        try {
            return (long) Double.parseDouble(String.valueOf(v));
        } catch (NumberFormatException e) {
            return fallback;
        }
    }

    public static double dbl(JSONObject o, String key, double fallback) {
        if (o == null || !o.has(key) || o.isNull(key)) {
            return fallback;
        }
        return o.optDouble(key, fallback);
    }

    public static boolean bool(JSONObject o, String key) {
        if (o == null || !o.has(key) || o.isNull(key)) {
            return false;
        }
        Object v = o.opt(key);
        if (v instanceof Boolean) {
            return (Boolean) v;
        }
        if (v instanceof Number) {
            return ((Number) v).intValue() != 0;
        }
        String s = String.valueOf(v).toLowerCase();
        return s.equals("true") || s.equals("1") || s.equals("yes") || s.equals("on");
    }

    public static JSONObject obj(JSONObject o, String key) {
        return o == null ? null : o.optJSONObject(key);
    }

    public static JSONArray arr(JSONObject o, String key) {
        JSONArray a = o == null ? null : o.optJSONArray(key);
        return a == null ? new JSONArray() : a;
    }

    public static List<JSONObject> list(JSONArray a) {
        List<JSONObject> out = new ArrayList<JSONObject>();
        if (a == null) {
            return out;
        }
        for (int i = 0; i < a.length(); i++) {
            JSONObject o = a.optJSONObject(i);
            if (o != null) {
                out.add(o);
            }
        }
        return out;
    }

    public static List<String> strings(JSONArray a) {
        List<String> out = new ArrayList<String>();
        if (a == null) {
            return out;
        }
        for (int i = 0; i < a.length(); i++) {
            if (!a.isNull(i)) {
                out.add(String.valueOf(a.opt(i)));
            }
        }
        return out;
    }

    public static List<String> keys(JSONObject o) {
        List<String> out = new ArrayList<String>();
        if (o == null) {
            return out;
        }
        Iterator<String> it = o.keys();
        while (it.hasNext()) {
            out.add(it.next());
        }
        return out;
    }

    /** Builds an object from alternating key/value arguments: Json.of("a", 1, "b", "x"). */
    public static JSONObject of(Object... kv) {
        JSONObject o = new JSONObject();
        for (int i = 0; i + 1 < kv.length; i += 2) {
            put(o, String.valueOf(kv[i]), kv[i + 1]);
        }
        return o;
    }

    public static JSONObject put(JSONObject o, String key, Object value) {
        try {
            o.put(key, value == null ? JSONObject.NULL : value);
        } catch (JSONException ignored) {
        }
        return o;
    }

    public static JSONObject parse(String s) {
        if (s == null) {
            return null;
        }
        try {
            return new JSONObject(s);
        } catch (JSONException e) {
            return null;
        }
    }

    public static JSONArray parseArray(String s) {
        if (s == null) {
            return new JSONArray();
        }
        try {
            return new JSONArray(s);
        } catch (JSONException e) {
            return new JSONArray();
        }
    }

    public static JSONArray array(List<?> items) {
        JSONArray a = new JSONArray();
        for (Object o : items) {
            a.put(o);
        }
        return a;
    }
}
