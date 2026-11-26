package com.upsl.admin;

import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.net.Uri;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.IOException;
import java.io.InputStream;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.UUID;

/**
 * Resumable chunked uploads (game files, trailers, music, backups) following the server protocol:
 * init → chunk (SHA-256 per chunk) → complete (SHA-256 of the whole file) → poll while "processing".
 * The server remembers received chunks, so a paused, interrupted or restarted upload continues where it stopped.
 * Tasks run one at a time inside {@link TransferService} (a foreground service) so the system keeps them alive.
 */
public final class Transfers {
    public static final String QUEUED = "queued";
    public static final String UPLOADING = "uploading";
    public static final String FINISHING = "finishing";
    public static final String PROCESSING = "processing";
    public static final String DONE = "done";
    public static final String FAILED = "failed";
    public static final String PAUSED = "paused";
    public static final String CANCELLED = "cancelled";

    public static final class Task {
        public final String id;
        public final Uri uri;
        public final String name;
        public final long size;
        public final String purpose;
        public final JSONObject fields;
        public final String title;
        public volatile String status = QUEUED;
        public volatile long sent;
        public volatile String uploadId;
        public volatile String error;
        public volatile JSONObject result;
        public volatile boolean pause;
        public volatile boolean cancel;
        public volatile double speed;
        public volatile long updatedAt = System.currentTimeMillis();

        Task(String id, Uri uri, String name, long size, String purpose, JSONObject fields, String title) {
            this.id = id;
            this.uri = uri;
            this.name = name;
            this.size = size;
            this.purpose = purpose;
            this.fields = fields == null ? new JSONObject() : fields;
            this.title = title;
        }

        public float fraction() {
            return size > 0 ? Math.min(1f, sent / (float) size) : 0f;
        }

        public boolean active() {
            return QUEUED.equals(status) || UPLOADING.equals(status) || FINISHING.equals(status) || PROCESSING.equals(status);
        }

        JSONObject toJson() {
            return Json.of("id", id, "uri", uri.toString(), "name", name, "size", size, "purpose", purpose, "fields", fields,
                    "title", title, "status", status, "sent", sent, "upload_id", uploadId, "error", error);
        }

        static Task fromJson(JSONObject o) {
            Task t = new Task(Json.str(o, "id", UUID.randomUUID().toString()), Uri.parse(Json.str(o, "uri", "")), Json.str(o, "name", "file"),
                    Json.lng(o, "size", 0), Json.str(o, "purpose", "game_file"), Json.obj(o, "fields"), Json.str(o, "title", ""));
            t.status = Json.str(o, "status", PAUSED);
            t.sent = Json.lng(o, "sent", 0);
            t.uploadId = Json.str(o, "upload_id");
            t.error = Json.str(o, "error");
            return t;
        }
    }

    public interface Listener {
        /** Called on the main thread, at most a few times per second. */
        void changed(Task t);
    }

    private static Transfers instance;
    private final Context app;
    private final List<Task> tasks = new ArrayList<Task>();
    private final Set<Listener> listeners = new HashSet<Listener>();
    private final Handler main = new Handler(Looper.getMainLooper());
    private final Map<String, Long> lastNotify = new HashMap<String, Long>();

    private Transfers(Context c) {
        app = c.getApplicationContext();
        restore();
    }

    public static synchronized Transfers get(Context c) {
        if (instance == null) {
            instance = new Transfers(c);
        }
        return instance;
    }

    // ------------------------------------------------------------------ task list

    public synchronized List<Task> all() {
        return new ArrayList<Task>(tasks);
    }

    public synchronized Task find(String id) {
        for (Task t : tasks) {
            if (t.id.equals(id)) {
                return t;
            }
        }
        return null;
    }

    public synchronized Task next() {
        for (Task t : tasks) {
            if (QUEUED.equals(t.status)) {
                return t;
            }
        }
        return null;
    }

    public synchronized boolean hasActive() {
        for (Task t : tasks) {
            if (t.active()) {
                return true;
            }
        }
        return false;
    }

    /** Queues a new upload and makes sure the transfer service runs. */
    public Task enqueue(Context ctx, Uri uri, String purpose, JSONObject fields, String title) {
        String name = Api.displayName(ctx, uri);
        long size = Api.fileSize(ctx, uri);
        Task t = new Task(UUID.randomUUID().toString(), uri, name, size, purpose, fields, title);
        synchronized (this) {
            tasks.add(0, t);
        }
        persist();
        notifyChanged(t, true);
        startService(ctx);
        return t;
    }

    public void resume(Context ctx, Task t) {
        t.pause = false;
        t.cancel = false;
        t.error = null;
        t.status = QUEUED;
        persist();
        notifyChanged(t, true);
        startService(ctx);
    }

    public void pause(Task t) {
        if (QUEUED.equals(t.status)) {
            t.status = PAUSED;
            persist();
            notifyChanged(t, true);
        } else {
            t.pause = true;
        }
    }

    /** Cancels locally and on the server (removes the received chunks there). */
    public void cancel(final Task t) {
        t.cancel = true;
        if (!UPLOADING.equals(t.status) && !FINISHING.equals(t.status)) {
            t.status = CANCELLED;
            persist();
            notifyChanged(t, true);
            if (t.uploadId != null) {
                Api.get(app).delete("/admin/uploads/" + t.uploadId, null);
            }
        }
    }

    public synchronized void remove(Task t) {
        tasks.remove(t);
        persist();
    }

    public synchronized void clearFinished() {
        List<Task> keep = new ArrayList<Task>();
        for (Task t : tasks) {
            if (!DONE.equals(t.status) && !CANCELLED.equals(t.status) && !FAILED.equals(t.status)) {
                keep.add(t);
            }
        }
        tasks.clear();
        tasks.addAll(keep);
        persist();
    }

    private void startService(Context ctx) {
        Intent i = new Intent(ctx, TransferService.class);
        try {
            if (Build.VERSION.SDK_INT >= 26) {
                ctx.startForegroundService(i);
            } else {
                ctx.startService(i);
            }
        } catch (RuntimeException e) {
            // background-start restrictions: the uploads screen restarts the queue when opened
            android.util.Log.w("Transfers", "cannot start service: " + e);
        }
    }

    // ------------------------------------------------------------------ listeners & persistence

    public void addListener(Listener l) {
        synchronized (listeners) {
            listeners.add(l);
        }
    }

    public void removeListener(Listener l) {
        synchronized (listeners) {
            listeners.remove(l);
        }
    }

    void notifyChanged(final Task t, boolean force) {
        long now = System.currentTimeMillis();
        t.updatedAt = now;
        synchronized (lastNotify) {
            Long last = lastNotify.get(t.id);
            if (!force && last != null && now - last < 300) {
                return;
            }
            lastNotify.put(t.id, now);
        }
        main.post(new Runnable() {
            @Override
            public void run() {
                List<Listener> copy;
                synchronized (listeners) {
                    copy = new ArrayList<Listener>(listeners);
                }
                for (Listener l : copy) {
                    l.changed(t);
                }
            }
        });
    }

    synchronized void persist() {
        JSONArray a = new JSONArray();
        for (Task t : tasks) {
            if (!CANCELLED.equals(t.status)) {
                a.put(t.toJson());
            }
            if (a.length() >= 40) {
                break;
            }
        }
        prefs().edit().putString("transfers", a.toString()).apply();
    }

    private void restore() {
        JSONArray a = Json.parseArray(prefs().getString("transfers", "[]"));
        for (JSONObject o : Json.list(a)) {
            Task t = Task.fromJson(o);
            if (t.active()) {
                // the process was killed mid-transfer: wait for the admin to resume it
                t.status = PAUSED;
                t.error = "توقف الرفع لأن التطبيق أُغلق، اضغط «استئناف» للمتابعة من حيث توقف";
            }
            tasks.add(t);
        }
    }

    private SharedPreferences prefs() {
        return app.getSharedPreferences("upsl_transfers", Context.MODE_PRIVATE);
    }

    // ------------------------------------------------------------------ the uploader (runs on the service thread)

    /** Runs one task to the end (done / failed / paused / cancelled). Never throws. */
    void run(Task t) {
        Api api = Api.get(app);
        t.status = UPLOADING;
        t.error = null;
        t.pause = false;
        notifyChanged(t, true);
        try {
            if (t.size <= 0) {
                throw new UploadException("تعذر معرفة حجم الملف المختار، اختر الملف من مدير الملفات مباشرة");
            }
            // 1. init (or resume the same server session)
            JSONObject init = Json.of("purpose", t.purpose, "filename", t.name, "size", t.size, "mime", app.getContentResolver().getType(t.uri));
            for (String k : Json.keys(t.fields)) {
                Json.put(init, k, t.fields.opt(k));
            }
            Api.Result r = retrying(api, "POST", "/admin/uploads/init", init, t);
            if (r == null) {
                return;
            }
            JSONObject s = r.obj();
            t.uploadId = Json.str(s, "upload_id");
            persist();
            long chunkSize = Json.lng(s, "chunk_size", 5L * 1024 * 1024);
            int total = Json.num(s, "total_chunks", (int) ((t.size + chunkSize - 1) / chunkSize));
            Set<Integer> received = new HashSet<Integer>();
            JSONArray rec = Json.arr(s, "received");
            for (int i = 0; i < rec.length(); i++) {
                received.add(rec.optInt(i));
            }
            // 2. chunks in order, hashing the whole file on the way (already received chunks are read and hashed only)
            MessageDigest whole = MessageDigest.getInstance("SHA-256");
            InputStream in = app.getContentResolver().openInputStream(t.uri);
            if (in == null) {
                throw new UploadException("تعذر فتح الملف؛ ربما نُقل أو حُذف من الجهاز");
            }
            byte[] buf = new byte[(int) chunkSize];
            long done = 0;
            long windowStart = System.currentTimeMillis();
            long windowBytes = 0;
            try {
                for (int index = 0; index < total; index++) {
                    if (t.cancel) {
                        throw new CancelledException();
                    }
                    if (t.pause) {
                        t.status = PAUSED;
                        t.error = null;
                        persist();
                        notifyChanged(t, true);
                        return;
                    }
                    int expected = (int) Math.min(chunkSize, t.size - (long) index * chunkSize);
                    int got = readFully(in, buf, expected);
                    if (got != expected) {
                        throw new UploadException("تغيّر حجم الملف أثناء الرفع (المتوقع " + Fmt.bytes(t.size) + ")، أعد اختيار الملف");
                    }
                    whole.update(buf, 0, got);
                    if (!received.contains(index)) {
                        MessageDigest cd = MessageDigest.getInstance("SHA-256");
                        cd.update(buf, 0, got);
                        String chunkHash = hex(cd.digest());
                        Map<String, String> h = new HashMap<String, String>();
                        h.put("X-Upload-Id", t.uploadId);
                        h.put("X-Chunk-Index", String.valueOf(index));
                        h.put("X-Chunk-Sha256", chunkHash);
                        byte[] body = got == buf.length ? buf : java.util.Arrays.copyOf(buf, got);
                        if (sendChunk(api, body, h, t) == null) {
                            return;
                        }
                        windowBytes += got;
                    }
                    done += got;
                    t.sent = done;
                    long now = System.currentTimeMillis();
                    if (now - windowStart > 1500) {
                        t.speed = windowBytes * 1000.0 / (now - windowStart);
                        windowStart = now;
                        windowBytes = 0;
                    }
                    notifyChanged(t, false);
                }
            } finally {
                in.close();
            }
            // 3. complete with the full-file hash
            t.status = FINISHING;
            t.speed = 0;
            notifyChanged(t, true);
            String sha = hex(whole.digest());
            Api.Result c = retrying(api, "POST", "/admin/uploads/complete", Json.of("upload_id", t.uploadId, "sha256", sha), t);
            if (c == null) {
                return;
            }
            JSONObject st = c.obj();
            // 4. large files are assembled by the server's queue: poll (and nudge the queue if no worker runs)
            long started = System.currentTimeMillis();
            boolean nudged = false;
            while ("processing".equals(Json.str(st, "status"))) {
                t.status = PROCESSING;
                notifyChanged(t, true);
                sleep(3000);
                if (t.cancel) {
                    throw new CancelledException();
                }
                if (!nudged && System.currentTimeMillis() - started > 12000) {
                    nudged = true;
                    api.callJson("POST", "/admin/queue/run", null, new JSONObject());
                }
                Api.Result p = api.callJson("GET", "/admin/uploads/" + t.uploadId, null, null);
                if (p.ok) {
                    st = p.obj();
                    if (System.currentTimeMillis() - started > 60000) {
                        nudged = false;
                        started = System.currentTimeMillis() - 12000;
                    }
                } else if (p.status != 0) {
                    throw new UploadException(p.error.describe());
                }
            }
            if ("failed".equals(Json.str(st, "status"))) {
                throw new UploadException(Json.str(st, "error", "فشلت معالجة الملف على الخادم"));
            }
            t.result = Json.obj(st, "result");
            t.status = DONE;
            t.sent = t.size;
            t.error = null;
        } catch (CancelledException e) {
            t.status = CANCELLED;
            if (t.uploadId != null) {
                api.callJson("DELETE", "/admin/uploads/" + t.uploadId, null, null);
            }
        } catch (UploadException e) {
            t.status = FAILED;
            t.error = e.getMessage();
        } catch (IOException e) {
            t.status = PAUSED;
            t.error = "تعذرت قراءة الملف: " + e.getMessage();
        } catch (NoSuchAlgorithmException e) {
            t.status = FAILED;
            t.error = "SHA-256 غير متاح على هذا الجهاز";
        } catch (RuntimeException e) {
            t.status = FAILED;
            t.error = "خطأ غير متوقع: " + e;
        }
        persist();
        notifyChanged(t, true);
    }

    private static final class UploadException extends Exception {
        UploadException(String m) {
            super(m);
        }
    }

    private static final class CancelledException extends Exception {
    }

    /** JSON call with retries for network errors and 5xx/429; returns null when the task was paused. */
    private Api.Result retrying(Api api, String method, String path, JSONObject body, Task t) throws UploadException, CancelledException {
        int attempt = 0;
        while (true) {
            if (t.cancel) {
                throw new CancelledException();
            }
            Api.Result r = api.callJson(method, path, null, body);
            if (r.ok) {
                return r;
            }
            if (!retryable(r.status) || attempt >= 7) {
                if (r.status == 0) {
                    pausedByNetwork(t, r.error.getMessage());
                    return null;
                }
                throw new UploadException(r.error.describe());
            }
            backoff(attempt++, t);
        }
    }

    private Api.Result sendChunk(Api api, byte[] body, Map<String, String> headers, Task t) throws UploadException, CancelledException {
        int attempt = 0;
        while (true) {
            if (t.cancel) {
                throw new CancelledException();
            }
            Api.Result r;
            try {
                r = api.call("POST", "/admin/uploads/chunk", null, body, "application/octet-stream", headers, 120000);
            } catch (IOException e) {
                r = new Api.Result(0, null, Api.networkError(e));
            }
            if (r.ok) {
                return r;
            }
            if (!retryable(r.status) || attempt >= 7) {
                if (r.status == 0) {
                    pausedByNetwork(t, r.error.getMessage());
                    return null;
                }
                throw new UploadException(r.error.describe());
            }
            backoff(attempt++, t);
        }
    }

    private void pausedByNetwork(Task t, String msg) {
        t.status = PAUSED;
        t.error = msg + " — اضغط «استئناف» عند عودة الاتصال، سيكمل الرفع من آخر جزء";
        persist();
        notifyChanged(t, true);
    }

    private static boolean retryable(int status) {
        return status == 0 || status == 408 || status == 429 || status >= 500;
    }

    private void backoff(int attempt, Task t) {
        long ms = Math.min(30000L, 1000L << Math.min(attempt, 5));
        t.error = "إعادة المحاولة بعد " + (ms / 1000) + " ث…";
        notifyChanged(t, true);
        sleep(ms);
        t.error = null;
    }

    private static void sleep(long ms) {
        try {
            Thread.sleep(ms);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }

    private static int readFully(InputStream in, byte[] buf, int len) throws IOException {
        int off = 0;
        while (off < len) {
            int n = in.read(buf, off, len - off);
            if (n < 0) {
                break;
            }
            off += n;
        }
        return off;
    }

    public static String hex(byte[] b) {
        char[] digits = "0123456789abcdef".toCharArray();
        char[] out = new char[b.length * 2];
        for (int i = 0; i < b.length; i++) {
            out[i * 2] = digits[(b[i] >> 4) & 0xF];
            out[i * 2 + 1] = digits[b[i] & 0xF];
        }
        return new String(out);
    }
}
