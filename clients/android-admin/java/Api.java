package com.upsl.admin;

import android.content.ContentResolver;
import android.content.Context;
import android.database.Cursor;
import android.net.Uri;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;
import android.provider.OpenableColumns;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;
import org.json.JSONTokener;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.io.UnsupportedEncodingException;
import java.net.HttpURLConnection;
import java.net.SocketTimeoutException;
import java.net.URL;
import java.net.URLEncoder;
import java.net.UnknownHostException;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import javax.net.ssl.SSLException;

/**
 * Talks to the library server's REST API (/api/v1). Every network call runs on a background thread;
 * async callbacks are delivered on the main thread.
 */
public final class Api {

    /** Result of one request: either ok with data/meta, or an error. */
    public static final class Result {
        public final int status;
        public final boolean ok;
        public final Object data;
        public final JSONObject meta;
        public final JSONObject body;
        public final Error error;

        Result(int status, JSONObject body, Error error) {
            this.status = status;
            this.body = body;
            this.error = error;
            this.ok = error == null && body != null && body.optBoolean("ok", false);
            this.data = body == null ? null : body.opt("data");
            this.meta = body == null ? null : body.optJSONObject("meta");
        }

        public JSONObject obj() {
            return data instanceof JSONObject ? (JSONObject) data : new JSONObject();
        }

        public JSONArray arr() {
            return data instanceof JSONArray ? (JSONArray) data : new JSONArray();
        }

        public int metaInt(String key, int fallback) {
            return Json.num(meta, key, fallback);
        }
    }

    /** API error with the server's code, Arabic message and per-field messages. */
    public static final class Error extends Exception {
        public final int status;
        public final String code;
        public final JSONObject fields;
        public final JSONObject raw;

        public Error(int status, String code, String message, JSONObject fields, JSONObject raw) {
            super(message);
            this.status = status;
            this.code = code == null ? "error" : code;
            this.fields = fields;
            this.raw = raw;
        }

        /** First field message, or the general message. */
        public String describe() {
            if (fields != null && fields.length() > 0) {
                for (String k : Json.keys(fields)) {
                    String m = Json.str(fields, k);
                    if (m != null) {
                        return m;
                    }
                }
            }
            return getMessage();
        }
    }

    public interface Callback {
        void done(Result r);
    }

    public interface Progress {
        /** Called on the worker thread. */
        void update(long done, long total);
    }

    /** Hook for an expired or revoked token (401 on an authenticated call). */
    public interface AuthListener {
        void onUnauthorized();
    }

    private static Api instance;
    private final Context app;
    private final ExecutorService pool = Executors.newFixedThreadPool(4);
    private final Handler main = new Handler(Looper.getMainLooper());
    private volatile AuthListener authListener;

    private Api(Context ctx) {
        app = ctx.getApplicationContext();
    }

    public static synchronized Api get(Context ctx) {
        if (instance == null) {
            instance = new Api(ctx);
        }
        return instance;
    }

    public void setAuthListener(AuthListener l) {
        authListener = l;
    }

    public Handler main() {
        return main;
    }

    public void runBackground(Runnable r) {
        pool.execute(r);
    }

    // ------------------------------------------------------------------ async helpers

    public void get(String path, Map<String, String> query, Callback cb) {
        async("GET", path, query, null, cb);
    }

    public void post(String path, JSONObject body, Callback cb) {
        async("POST", path, null, body, cb);
    }

    public void put(String path, JSONObject body, Callback cb) {
        async("PUT", path, null, body, cb);
    }

    public void delete(String path, Callback cb) {
        async("DELETE", path, null, null, cb);
    }

    public void async(final String method, final String path, final Map<String, String> query, final JSONObject body, final Callback cb) {
        pool.execute(new Runnable() {
            @Override
            public void run() {
                final Result r = callJson(method, path, query, body);
                deliver(cb, r);
            }
        });
    }

    /** Multipart upload of a picked file (images, CSV/JSON imports…) with optional extra form fields. */
    public void upload(final String path, final Uri uri, final String field, final Map<String, String> fields, final Progress progress, final Callback cb) {
        pool.execute(new Runnable() {
            @Override
            public void run() {
                Result r;
                try {
                    r = multipart(path, uri, field, fields, progress);
                } catch (IOException e) {
                    r = new Result(0, null, networkError(e));
                }
                deliver(cb, r);
            }
        });
    }

    private void deliver(final Callback cb, final Result r) {
        if (r.status == 401 && Session.get(app).isLoggedIn() && authListener != null) {
            main.post(new Runnable() {
                @Override
                public void run() {
                    AuthListener l = authListener;
                    if (l != null) {
                        l.onUnauthorized();
                    }
                }
            });
        }
        if (cb != null) {
            main.post(new Runnable() {
                @Override
                public void run() {
                    cb.done(r);
                }
            });
        }
    }

    // ------------------------------------------------------------------ synchronous core (worker threads only)

    public String url(String path, Map<String, String> query) {
        String base = Session.get(app).server();
        String u = path.startsWith("http://") || path.startsWith("https://") ? path
                : base + (path.startsWith("/api/") ? path : "/api/v1" + (path.startsWith("/") ? path : "/" + path));
        if (query != null && !query.isEmpty()) {
            StringBuilder sb = new StringBuilder(u);
            sb.append(u.contains("?") ? '&' : '?');
            boolean first = true;
            for (Map.Entry<String, String> e : query.entrySet()) {
                if (e.getValue() == null || e.getValue().length() == 0) {
                    continue;
                }
                if (!first) {
                    sb.append('&');
                }
                sb.append(enc(e.getKey())).append('=').append(enc(e.getValue()));
                first = false;
            }
            u = sb.toString();
            if (u.endsWith("?") || u.endsWith("&")) {
                u = u.substring(0, u.length() - 1);
            }
        }
        return u;
    }

    public static String enc(String s) {
        try {
            return URLEncoder.encode(s, "UTF-8");
        } catch (UnsupportedEncodingException e) {
            return s;
        }
    }

    public static Map<String, String> q(String... kv) {
        Map<String, String> m = new LinkedHashMap<String, String>();
        for (int i = 0; i + 1 < kv.length; i += 2) {
            m.put(kv[i], kv[i + 1]);
        }
        return m;
    }

    public Result callJson(String method, String path, Map<String, String> query, JSONObject body) {
        try {
            byte[] bytes = body == null ? null : body.toString().getBytes("UTF-8");
            return call(method, path, query, bytes, bytes == null ? null : "application/json; charset=utf-8", null, 60000);
        } catch (IOException e) {
            return new Result(0, null, networkError(e));
        }
    }

    /** Raw request; returns a Result for any HTTP status, throws only for network failures. */
    public Result call(String method, String path, Map<String, String> query, byte[] body, String contentType,
                       Map<String, String> headers, int readTimeoutMs) throws IOException {
        HttpURLConnection c = open(method, url(path, query), readTimeoutMs);
        try {
            if (headers != null) {
                for (Map.Entry<String, String> h : headers.entrySet()) {
                    c.setRequestProperty(h.getKey(), h.getValue());
                }
            }
            if (body != null) {
                c.setDoOutput(true);
                c.setRequestProperty("Content-Type", contentType == null ? "application/octet-stream" : contentType);
                c.setFixedLengthStreamingMode(body.length);
                OutputStream out = c.getOutputStream();
                try {
                    out.write(body);
                } finally {
                    out.close();
                }
            }
            return read(c);
        } finally {
            c.disconnect();
        }
    }

    private HttpURLConnection open(String method, String url, int readTimeoutMs) throws IOException {
        HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
        c.setRequestMethod(method);
        c.setConnectTimeout(15000);
        c.setReadTimeout(readTimeoutMs);
        c.setUseCaches(false);
        c.setRequestProperty("Accept", "application/json");
        c.setRequestProperty("User-Agent", "UPSL-Admin-Android/" + BuildInfo.VERSION + " (Android " + Build.VERSION.RELEASE + ")");
        String token = Session.get(app).token();
        if (token != null && token.length() > 0) {
            c.setRequestProperty("Authorization", "Bearer " + token);
        }
        return c;
    }

    private Result read(HttpURLConnection c) throws IOException {
        int status = c.getResponseCode();
        InputStream in = status >= 400 ? c.getErrorStream() : c.getInputStream();
        String text = in == null ? "" : readAll(in);
        JSONObject json = null;
        try {
            Object v = new JSONTokener(text).nextValue();
            if (v instanceof JSONObject) {
                json = (JSONObject) v;
            }
        } catch (JSONException ignored) {
        }
        if (json == null) {
            String msg = status == 413 ? "الملف أكبر من الحد المسموح على الخادم"
                    : status == 404 ? "الخادم لا يعرف هذا المسار — تأكد أن رابط الخادم صحيح"
                    : status >= 500 ? "خطأ في الخادم (HTTP " + status + ")"
                    : "استجابة غير متوقعة من الخادم (HTTP " + status + ")";
            return new Result(status, null, new Error(status, "bad_response", msg, null, null));
        }
        if (!json.optBoolean("ok", false)) {
            JSONObject err = json.optJSONObject("error");
            String code = err == null ? "error" : err.optString("code", "error");
            String msg = err == null ? "تعذر تنفيذ الطلب" : Json.str(err, "message", "تعذر تنفيذ الطلب");
            if (status == 429) {
                String retry = c.getHeaderField("Retry-After");
                if (retry != null) {
                    msg = msg + " (" + retry + " ث)";
                }
            }
            return new Result(status, json, new Error(status, code, msg, err == null ? null : err.optJSONObject("fields"), err));
        }
        return new Result(status, json, null);
    }

    public static String readAll(InputStream in) throws IOException {
        ByteArrayOutputStream bos = new ByteArrayOutputStream();
        byte[] buf = new byte[16384];
        int n;
        try {
            while ((n = in.read(buf)) > 0) {
                bos.write(buf, 0, n);
            }
        } finally {
            in.close();
        }
        return bos.toString("UTF-8");
    }

    public static Error networkError(IOException e) {
        String msg;
        if (e instanceof UnknownHostException) {
            msg = "تعذر الوصول إلى الخادم — تحقق من الرابط والاتصال بالإنترنت";
        } else if (e instanceof SocketTimeoutException) {
            msg = "انتهت مهلة الاتصال بالخادم، حاول مجدداً";
        } else if (e instanceof SSLException) {
            msg = "شهادة HTTPS للخادم غير موثوقة أو غير صالحة";
        } else {
            msg = "انقطع الاتصال بالخادم: " + (e.getMessage() == null ? e.getClass().getSimpleName() : e.getMessage());
        }
        return new Error(0, "network", msg, null, null);
    }

    // ------------------------------------------------------------------ multipart & download

    public Result multipart(String path, Uri uri, String field, Map<String, String> fields, Progress progress) throws IOException {
        ContentResolver cr = app.getContentResolver();
        String name = displayName(app, uri);
        long size = fileSize(app, uri);
        String mime = cr.getType(uri);
        if (mime == null) {
            mime = "application/octet-stream";
        }
        String boundary = "----upsl" + System.currentTimeMillis();
        StringBuilder head = new StringBuilder();
        if (fields != null) {
            for (Map.Entry<String, String> f : fields.entrySet()) {
                if (f.getValue() == null) {
                    continue;
                }
                head.append("--").append(boundary).append("\r\n")
                        .append("Content-Disposition: form-data; name=\"").append(f.getKey()).append("\"\r\n\r\n")
                        .append(f.getValue()).append("\r\n");
            }
        }
        head.append("--").append(boundary).append("\r\n")
                .append("Content-Disposition: form-data; name=\"").append(field).append("\"; filename=\"")
                .append(name.replace("\"", "")).append("\"\r\n")
                .append("Content-Type: ").append(mime).append("\r\n\r\n");
        byte[] headBytes = head.toString().getBytes("UTF-8");
        byte[] tail = ("\r\n--" + boundary + "--\r\n").getBytes("UTF-8");

        HttpURLConnection c = open("POST", url(path, null), 300000);
        try {
            c.setDoOutput(true);
            c.setRequestProperty("Content-Type", "multipart/form-data; boundary=" + boundary);
            if (size >= 0) {
                c.setFixedLengthStreamingMode(headBytes.length + size + tail.length);
            } else {
                c.setChunkedStreamingMode(64 * 1024);
            }
            OutputStream out = c.getOutputStream();
            InputStream in = cr.openInputStream(uri);
            if (in == null) {
                throw new IOException("تعذر قراءة الملف المختار");
            }
            try {
                out.write(headBytes);
                byte[] buf = new byte[64 * 1024];
                long done = 0;
                int n;
                while ((n = in.read(buf)) > 0) {
                    out.write(buf, 0, n);
                    done += n;
                    if (progress != null) {
                        progress.update(done, size);
                    }
                }
                out.write(tail);
            } finally {
                in.close();
                out.close();
            }
            return read(c);
        } finally {
            c.disconnect();
        }
    }

    /** Streams an authenticated GET (e.g. a backup) into the given output; returns the byte count. */
    public long download(String path, OutputStream out, Progress progress) throws IOException, Error {
        HttpURLConnection c = open("GET", url(path, null), 120000);
        c.setRequestProperty("Accept", "*/*");
        try {
            int status = c.getResponseCode();
            if (status >= 400) {
                Result r = read(c);
                throw r.error != null ? r.error : new Error(status, "download_failed", "تعذر التنزيل (HTTP " + status + ")", null, null);
            }
            long total = c.getContentLength();
            InputStream in = c.getInputStream();
            long done = 0;
            try {
                byte[] buf = new byte[64 * 1024];
                int n;
                while ((n = in.read(buf)) > 0) {
                    out.write(buf, 0, n);
                    done += n;
                    if (progress != null) {
                        progress.update(done, total);
                    }
                }
            } finally {
                in.close();
                out.close();
            }
            return done;
        } finally {
            c.disconnect();
        }
    }

    // ------------------------------------------------------------------ content helpers

    public static String displayName(Context ctx, Uri uri) {
        String name = null;
        Cursor cur = null;
        try {
            cur = ctx.getContentResolver().query(uri, new String[]{OpenableColumns.DISPLAY_NAME}, null, null, null);
            if (cur != null && cur.moveToFirst()) {
                name = cur.getString(0);
            }
        } catch (Exception ignored) {
        } finally {
            if (cur != null) {
                cur.close();
            }
        }
        if (name == null) {
            name = uri.getLastPathSegment();
        }
        return name == null ? "file" : name;
    }

    public static long fileSize(Context ctx, Uri uri) {
        Cursor cur = null;
        try {
            cur = ctx.getContentResolver().query(uri, new String[]{OpenableColumns.SIZE}, null, null, null);
            if (cur != null && cur.moveToFirst() && !cur.isNull(0)) {
                return cur.getLong(0);
            }
        } catch (Exception ignored) {
        } finally {
            if (cur != null) {
                cur.close();
            }
        }
        return -1;
    }
}
