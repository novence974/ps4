package com.upsl.admin;

import android.content.Context;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.os.Handler;
import android.os.Looper;
import android.util.LruCache;
import android.widget.ImageView;

import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.security.MessageDigest;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/** Loads covers and thumbnails: memory LRU + small disk cache, downsampled to the view size. */
public final class ImageLoader {
    private static ImageLoader instance;
    private final LruCache<String, Bitmap> memory;
    private final ExecutorService pool = Executors.newFixedThreadPool(3);
    private final Handler main = new Handler(Looper.getMainLooper());
    private final File dir;

    private ImageLoader(Context c) {
        int max = (int) Math.min(Runtime.getRuntime().maxMemory() / 8, 48L * 1024 * 1024);
        memory = new LruCache<String, Bitmap>(max) {
            @Override
            protected int sizeOf(String key, Bitmap value) {
                return value.getByteCount();
            }
        };
        dir = new File(c.getCacheDir(), "images");
        if (!dir.exists() && !dir.mkdirs()) {
            android.util.Log.w("ImageLoader", "cannot create cache dir");
        }
    }

    public static synchronized ImageLoader get(Context c) {
        if (instance == null) {
            instance = new ImageLoader(c.getApplicationContext());
        }
        return instance;
    }

    /** Picks the best URL from a media object of the API ({"sm": …, "md": …, "sm_jpg": …}). */
    public static String pick(JSONObject media, String... variants) {
        if (media == null) {
            return null;
        }
        for (String v : variants) {
            String u = Json.str(media, v);
            if (u != null) {
                return u;
            }
        }
        for (String k : Json.keys(media)) {
            String u = Json.str(media, k);
            if (u != null) {
                return u;
            }
        }
        return null;
    }

    public void load(final String url, final ImageView view, final int targetPx) {
        view.setTag(R.id.upsl_image_url, url);
        if (url == null) {
            view.setImageDrawable(null);
            return;
        }
        Bitmap cached = memory.get(url);
        if (cached != null) {
            view.setImageBitmap(cached);
            return;
        }
        view.setImageDrawable(null);
        pool.execute(new Runnable() {
            @Override
            public void run() {
                final Bitmap b = fetch(url, targetPx);
                if (b != null) {
                    memory.put(url, b);
                }
                main.post(new Runnable() {
                    @Override
                    public void run() {
                        if (url.equals(view.getTag(R.id.upsl_image_url)) && b != null) {
                            view.setImageBitmap(b);
                            view.setAlpha(0f);
                            view.animate().alpha(1f).setDuration(160).start();
                        }
                    }
                });
            }
        });
    }

    public void evict(String url) {
        if (url != null) {
            memory.remove(url);
            File f = new File(dir, key(url));
            if (f.exists() && !f.delete()) {
                android.util.Log.w("ImageLoader", "cannot delete cached image");
            }
        }
    }

    private Bitmap fetch(String url, int target) {
        try {
            File f = new File(dir, key(url));
            byte[] data;
            if (f.exists() && f.length() > 0) {
                data = readFile(f);
            } else {
                data = download(url);
                if (data == null) {
                    return null;
                }
                FileOutputStream out = new FileOutputStream(f);
                try {
                    out.write(data);
                } finally {
                    out.close();
                }
            }
            BitmapFactory.Options o = new BitmapFactory.Options();
            o.inJustDecodeBounds = true;
            BitmapFactory.decodeByteArray(data, 0, data.length, o);
            int sample = 1;
            if (target > 0) {
                while (o.outWidth / (sample * 2) >= target && o.outHeight / (sample * 2) >= target) {
                    sample *= 2;
                }
            }
            BitmapFactory.Options d = new BitmapFactory.Options();
            d.inSampleSize = sample;
            return BitmapFactory.decodeByteArray(data, 0, data.length, d);
        } catch (IOException e) {
            return null;
        } catch (OutOfMemoryError e) {
            memory.evictAll();
            return null;
        }
    }

    private static byte[] download(String url) throws IOException {
        HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
        c.setConnectTimeout(15000);
        c.setReadTimeout(30000);
        c.setRequestProperty("Accept", "image/webp,image/*");
        try {
            if (c.getResponseCode() != 200) {
                return null;
            }
            InputStream in = c.getInputStream();
            ByteArrayOutputStream bos = new ByteArrayOutputStream();
            byte[] buf = new byte[16384];
            int n;
            try {
                while ((n = in.read(buf)) > 0) {
                    bos.write(buf, 0, n);
                    if (bos.size() > 20 * 1024 * 1024) {
                        return null;
                    }
                }
            } finally {
                in.close();
            }
            return bos.toByteArray();
        } finally {
            c.disconnect();
        }
    }

    private static byte[] readFile(File f) throws IOException {
        java.io.FileInputStream in = new java.io.FileInputStream(f);
        try {
            ByteArrayOutputStream bos = new ByteArrayOutputStream((int) f.length());
            byte[] buf = new byte[16384];
            int n;
            while ((n = in.read(buf)) > 0) {
                bos.write(buf, 0, n);
            }
            return bos.toByteArray();
        } finally {
            in.close();
        }
    }

    private static String key(String url) {
        try {
            MessageDigest md = MessageDigest.getInstance("SHA-1");
            byte[] h = md.digest(url.getBytes("UTF-8"));
            StringBuilder sb = new StringBuilder();
            for (byte b : h) {
                sb.append(String.format(java.util.Locale.US, "%02x", b));
            }
            return sb.toString();
        } catch (Exception e) {
            return String.valueOf(url.hashCode());
        }
    }
}
