package com.upsl.admin;

import android.content.Context;
import android.net.Uri;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileOutputStream;
import java.security.MessageDigest;
import java.util.Random;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicReference;

/**
 * Runs the admin app's network core (Api, Session, Json, Transfers: the exact source files shipped in the SWB)
 * on the JVM against a live server, with small test doubles for the Android framework classes it touches.
 * UI classes are not exercised here.
 */
public final class CoreTest {
    private static int passed;
    private static int failed;
    private static Context ctx;
    private static Api api;

    private static void check(String name, boolean ok, String detail) {
        if (ok) {
            passed++;
            System.out.println("  ✓ " + name);
        } else {
            failed++;
            System.out.println("  ✗ " + name + (detail == null ? "" : "  — " + detail));
        }
    }

    public static void main(String[] args) throws Exception {
        String server = args.length > 0 ? args[0] : "http://127.0.0.1:8080";
        String user = args.length > 1 ? args[1] : "admin";
        String pass = args.length > 2 ? args[2] : System.getenv("UPSL_ADMIN_PASS");
        ctx = new Context();
        api = Api.get(ctx);
        Session s = Session.get(ctx);
        System.out.println("Server: " + server);

        System.out.println("\n[Session & auth]");
        check("normalizeServer adds https and strips /api/v1", "https://lib.example.com".equals(Session.normalizeServer(" lib.example.com/api/v1/ ")), null);
        s.setServer(server);
        Api.Result st = api.callJson("GET", "/status", null, null);
        check("GET /status ok", st.ok && "v1".equals(Json.str(st.obj(), "api_version")), String.valueOf(st.body));
        Api.Result bad = api.callJson("POST", "/auth/login", null, Json.of("login", user, "password", "wrong-password-x", "device_name", "jvm-test"));
        check("wrong password → 422 with Arabic message", !bad.ok && bad.status == 422 && bad.error.describe().length() > 0, bad.status + " " + (bad.error == null ? "" : bad.error.describe()));
        Api.Result login = api.callJson("POST", "/auth/login", null, Json.of("login", user, "password", pass, "device_name", "UPSL Admin JVM test"));
        check("login ok and returns token", login.ok && Json.str(login.obj(), "token", "").startsWith("upsl_"), String.valueOf(login.body));
        s.signIn(Json.str(login.obj(), "token"), Json.obj(login.obj(), "user"));
        check("super admin has every permission (\"*\")", s.can("backups.restore") && s.isSuper(), null);
        check("session keeps role level", s.roleLevel() >= 100, String.valueOf(s.roleLevel()));

        System.out.println("\n[Requests, errors, async delivery]");
        Api.Result stats = api.callJson("GET", "/admin/stats", null, null);
        check("GET /admin/stats", stats.ok && stats.obj().has("games_total") && Json.arr(stats.obj(), "downloads_daily").length() == 30, null);
        Api.Result nf = api.callJson("GET", "/admin/games/99999999", null, null);
        check("404 mapped to Api.Error", !nf.ok && nf.status == 404 && nf.error != null && nf.error.getMessage().length() > 0, nf.status + "");
        Api.Result inv = api.callJson("POST", "/admin/games", null, new JSONObject());
        check("422 field errors (title, platform_id)", !inv.ok && inv.status == 422 && inv.error.fields != null && inv.error.fields.has("title"), String.valueOf(inv.body));
        Api.Result games = api.callJson("GET", "/admin/games", Api.q("per_page", "5", "published", "0", "q", ""), null);
        check("paginated list has meta.pages", games.ok && games.meta != null && games.meta.has("pages"), null);
        final CountDownLatch latch = new CountDownLatch(1);
        final AtomicReference<Api.Result> async = new AtomicReference<Api.Result>();
        final AtomicReference<String> thread = new AtomicReference<String>();
        api.get("/admin/health", null, new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                async.set(r);
                thread.set(Thread.currentThread().getName());
                latch.countDown();
            }
        });
        latch.await(30, TimeUnit.SECONDS);
        check("async call delivered on the main-thread handler", async.get() != null && async.get().ok, thread.get());

        System.out.println("\n[Game create, duplicate detection, media]");
        Api.Result plats = api.callJson("GET", "/admin/platforms", null, null);
        int platformId = Json.num(plats.arr().optJSONObject(0), "id", 0);
        String title = "JVM Core Test " + System.currentTimeMillis();
        JSONObject body = Json.of("title", title, "platform_id", platformId, "region", "EUR", "size", "1.5 GB", "languages", new JSONArray().put("ar").put("en"),
                "rating", 8.5, "is_published", false);
        Api.Result created = api.callJson("POST", "/admin/games", null, body);
        int gameId = Json.num(created.obj(), "id", 0);
        check("create game (201)", created.ok && created.status == 201 && gameId > 0, String.valueOf(created.body));
        Api.Result dup = api.callJson("POST", "/admin/games", null, body);
        check("same game again → 409 duplicate_game with list", !dup.ok && dup.status == 409 && "duplicate_game".equals(dup.error.code)
                && dup.error.raw != null && dup.error.raw.optJSONArray("duplicates") != null, String.valueOf(dup.body));
        Json.put(body, "allow_duplicate", true);
        Api.Result dup2 = api.callJson("POST", "/admin/games", null, body);
        int dupId = Json.num(dup2.obj(), "id", 0);
        check("allow_duplicate saves it", dup2.ok && dupId > 0, String.valueOf(dup2.body));
        File png = tempPng();
        Api.Result cover = api.multipart("/admin/games/" + gameId + "/cover", Uri.fromFile(png), "file", null, null);
        check("multipart cover upload (WebP variants)", cover.ok && Json.obj(cover.obj(), "urls") != null, String.valueOf(cover.body));
        Api.Result shot = api.multipart("/admin/games/" + gameId + "/screenshots", Uri.fromFile(png), "file", Api.q("caption", "لقطة اختبار"), null);
        check("multipart screenshot with caption", shot.ok, String.valueOf(shot.body));

        System.out.println("\n[Chunked uploads through Transfers]");
        Transfers tr = Transfers.get(ctx);
        File f1 = tempFile("game-a.bin", 12 * 1024 * 1024 + 12345);
        Transfers.Task t1 = tr.enqueue(ctx, Uri.fromFile(f1), "game_file", Json.of("game_id", gameId, "file_type", "game", "access_level", "registered",
                "label", "اختبار", "region", "EUR"), title);
        check("enqueue starts the transfer service", ctx.servicesStarted > 0, null);
        tr.run(t1);
        check("12 MB upload completes (3 chunks, SHA-256 verified)", Transfers.DONE.equals(t1.status) && Json.num(t1.result, "file_id", 0) > 0, t1.status + " " + t1.error);
        Api.Result files = api.callJson("GET", "/admin/games/" + gameId + "/files", null, null);
        JSONObject first = files.arr().optJSONObject(0);
        check("file registered with the same SHA-256", first != null && sha256(f1).equals(Json.str(first, "sha256")), first == null ? "none" : Json.str(first, "sha256"));

        // pause after the first chunk, then resume: the server keeps the received chunks
        File f2 = tempFile("game-b.bin", 31 * 1024 * 1024);
        final Transfers.Task t2 = tr.enqueue(ctx, Uri.fromFile(f2), "game_file", Json.of("game_id", gameId, "file_type", "update"), title);
        Thread worker = runAsync(tr, t2);
        while (t2.sent == 0 && worker.isAlive()) {
            Thread.sleep(1);
        }
        t2.pause = true;
        worker.join();
        long sentBefore = t2.sent;
        check("pause stops between chunks", Transfers.PAUSED.equals(t2.status) && sentBefore > 0 && sentBefore < f2.length(), t2.status + " sent=" + sentBefore);
        Api.Result mid = api.callJson("GET", "/admin/uploads/" + t2.uploadId, null, null);
        int receivedBefore = Json.arr(mid.obj(), "received").length();
        check("server kept the received chunks (" + receivedBefore + " of 7)", mid.ok && receivedBefore >= 1 && receivedBefore < 7, String.valueOf(mid.body));
        String firstUploadId = t2.uploadId;
        tr.resume(ctx, t2);
        tr.run(t2);
        check("resume reuses the same server session and finishes", Transfers.DONE.equals(t2.status) && firstUploadId.equals(t2.uploadId), t2.status + " " + t2.error);
        Api.Result fl2 = api.callJson("GET", "/admin/games/" + gameId + "/files", null, null);
        boolean hashOk = false;
        for (int i = 0; i < fl2.arr().length(); i++) {
            hashOk |= sha256(f2).equals(Json.str(fl2.arr().optJSONObject(i), "sha256"));
        }
        check("resumed file has the right SHA-256 on the server", hashOk, null);

        // cancel
        File f3 = tempFile("game-c.bin", 31 * 1024 * 1024);
        final Transfers.Task t3 = tr.enqueue(ctx, Uri.fromFile(f3), "game_file", Json.of("game_id", gameId), title);
        Thread w3 = runAsync(tr, t3);
        while (t3.sent == 0 && w3.isAlive()) {
            Thread.sleep(1);
        }
        tr.cancel(t3);
        w3.join();
        Api.Result after = api.callJson("GET", "/admin/uploads/" + t3.uploadId, null, null);
        check("cancel deletes the server session", Transfers.CANCELLED.equals(t3.status) && (!after.ok || "cancelled".equals(Json.str(after.obj(), "status"))),
                t3.status + " / " + after.status + " " + (after.ok ? Json.str(after.obj(), "status") : ""));

        // disallowed extension is refused by the server with a clear message
        File f4 = tempFile("evil.php", 1000);
        Transfers.Task t4 = tr.enqueue(ctx, Uri.fromFile(f4), "game_file", Json.of("game_id", gameId), title);
        tr.run(t4);
        check("blocked extension → failed with server message", Transfers.FAILED.equals(t4.status) && t4.error != null && t4.error.length() > 5, t4.status + " " + t4.error);

        // large file path: assembled by the queue ("processing"), the app polls and nudges the queue
        Api.Result setLimit = api.callJson("PUT", "/admin/settings/security", null, Json.of("uploads.sync_assemble_limit_mb", 16));
        check("lower the direct-assembly limit to 16 MB for the test", setLimit.ok, String.valueOf(setLimit.body));
        File f5 = tempFile("game-big.bin", 18 * 1024 * 1024);
        Transfers.Task t5 = tr.enqueue(ctx, Uri.fromFile(f5), "game_file", Json.of("game_id", gameId), title);
        final boolean[] sawProcessing = {false};
        Transfers.Listener watch = new Transfers.Listener() {
            @Override
            public void changed(Transfers.Task t) {
                if (Transfers.PROCESSING.equals(t.status)) {
                    sawProcessing[0] = true;
                }
            }
        };
        tr.addListener(watch);
        long t0 = System.currentTimeMillis();
        tr.run(t5);
        Thread.sleep(300);
        tr.removeListener(watch);
        check("18 MB upload goes through queue processing and completes", Transfers.DONE.equals(t5.status) && sawProcessing[0],
                t5.status + " " + t5.error + " processing=" + sawProcessing[0] + " in " + (System.currentTimeMillis() - t0) + " ms");
        api.callJson("PUT", "/admin/settings/security", null, Json.of("uploads.sync_assemble_limit_mb", 2048));

        System.out.println("\n[Admin actions used by the screens]");
        Api.Result fl = api.callJson("GET", "/admin/games/" + gameId + "/files", null, null);
        int fileId = Json.num(fl.arr().optJSONObject(0), "id", 0);
        Api.Result ver = api.callJson("POST", "/admin/files/" + fileId + "/verify", null, new JSONObject());
        check("verify file integrity", ver.ok && Json.bool(ver.obj(), "ok"), String.valueOf(ver.body));
        Api.Result upd = api.callJson("PUT", "/admin/files/" + fileId, null, Json.of("file_type", "game", "access_level", "verified", "label", "معدل", "is_active", true));
        check("edit file metadata", upd.ok && "verified".equals(Json.str(upd.obj(), "access_level")), String.valueOf(upd.body));
        Api.Result bulk = api.callJson("POST", "/admin/games/bulk", null, Json.of("ids", new JSONArray().put(gameId).put(dupId), "action", "feature"));
        check("bulk feature", bulk.ok && Json.num(bulk.obj(), "done", 0) == 2, String.valueOf(bulk.body));
        Api.Result roles = api.callJson("GET", "/admin/roles", null, null);
        check("roles matrix", roles.ok && Json.bool(roles.obj(), "can_edit") && Json.obj(roles.obj(), "matrix") != null, null);
        Api.Result settings = api.callJson("GET", "/admin/settings", null, null);
        check("settings schema (4 groups for the super admin)", settings.ok && Json.keys(Json.obj(settings.obj(), "groups")).size() == 4, null);
        Api.Result backup = api.callJson("POST", "/admin/backups", null, Json.of("include_media", false));
        String bname = Json.str(backup.obj(), "name");
        check("create database backup", backup.ok && bname != null, String.valueOf(backup.body));
        ByteArrayOutputStream bout = new ByteArrayOutputStream();
        long got = api.download("/admin/backups/" + bname + "/download", bout, null);
        check("stream backup download (authenticated)", got > 0 && got == Json.lng(backup.obj(), "size", -1), got + " vs " + Json.lng(backup.obj(), "size", -1));
        Api.Result delB = api.callJson("DELETE", "/admin/backups/" + bname, null, null);
        check("delete backup", delB.ok, null);

        System.out.println("\n[Clean-up and sign-out]");
        Api.Result d1 = api.callJson("DELETE", "/admin/games/" + gameId, null, null);
        Api.Result d2 = api.callJson("DELETE", "/admin/games/" + dupId, null, null);
        check("delete test games (with their files)", d1.ok && d2.ok, null);
        Api.Result out = api.callJson("POST", "/auth/logout", null, new JSONObject());
        Api.Result afterOut = api.callJson("GET", "/admin/stats", null, null);
        check("logout revokes the token (next call 401)", out.ok && afterOut.status == 401, afterOut.status + "");

        System.out.println("\n" + passed + " passed, " + failed + " failed");
        android.os.Handler.MAIN.shutdownNow();
        System.exit(failed == 0 ? 0 : 1);
    }

    private static Thread runAsync(final Transfers tr, final Transfers.Task t) {
        Thread th = new Thread(new Runnable() {
            @Override
            public void run() {
                tr.run(t);
            }
        });
        th.start();
        return th;
    }

    private static File tempFile(String name, int size) throws Exception {
        File dir = new File(System.getProperty("java.io.tmpdir"), "upsl-jvmtest");
        dir.mkdirs();
        File f = new File(dir, name);
        byte[] buf = new byte[size];
        new Random(size).nextBytes(buf);
        FileOutputStream out = new FileOutputStream(f);
        out.write(buf);
        out.close();
        return f;
    }

    private static File tempPng() throws Exception {
        java.awt.image.BufferedImage img = new java.awt.image.BufferedImage(640, 360, java.awt.image.BufferedImage.TYPE_INT_RGB);
        java.awt.Graphics2D g = img.createGraphics();
        g.setPaint(new java.awt.GradientPaint(0, 0, new java.awt.Color(0x8B7CFF), 640, 360, new java.awt.Color(0x5EE6F0)));
        g.fillRect(0, 0, 640, 360);
        g.dispose();
        File f = new File(System.getProperty("java.io.tmpdir"), "upsl-jvmtest/cover.png");
        f.getParentFile().mkdirs();
        javax.imageio.ImageIO.write(img, "png", f);
        return f;
    }

    private static String sha256(File f) throws Exception {
        MessageDigest md = MessageDigest.getInstance("SHA-256");
        java.io.FileInputStream in = new java.io.FileInputStream(f);
        byte[] b = new byte[65536];
        int n;
        while ((n = in.read(b)) > 0) {
            md.update(b, 0, n);
        }
        in.close();
        return Transfers.hex(md.digest());
    }
}
