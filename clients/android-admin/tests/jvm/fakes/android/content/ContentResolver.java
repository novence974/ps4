package android.content;

import android.database.Cursor;
import android.net.Uri;

import java.io.File;
import java.io.FileInputStream;
import java.io.FileNotFoundException;
import java.io.InputStream;

/** Resolves file:// URIs from the local disk (the real one resolves SAF documents). */
public class ContentResolver {
    public InputStream openInputStream(Uri uri) throws FileNotFoundException { return new FileInputStream(new File(uri.getPath())); }

    public String getType(Uri uri) {
        String p = uri.getPath().toLowerCase();
        if (p.endsWith(".png")) return "image/png";
        if (p.endsWith(".jpg")) return "image/jpeg";
        if (p.endsWith(".csv")) return "text/csv";
        return "application/octet-stream";
    }

    public Cursor query(Uri uri, String[] projection, String sel, String[] args, String order) {
        final File f = new File(uri.getPath());
        final String col = projection[0];
        return new Cursor() {
            public boolean moveToFirst() { return f.exists(); }
            public String getString(int i) { return f.getName(); }
            public long getLong(int i) { return f.length(); }
            public boolean isNull(int i) { return !f.exists(); }
            public void close() { }
        };
    }
}
