package android.net;

/** Minimal Uri: file:///path and http(s) URLs. */
public class Uri {
    private final String s;
    private Uri(String s) { this.s = s; }
    public static Uri parse(String s) { return new Uri(s); }
    public static Uri fromFile(java.io.File f) { return new Uri("file://" + f.getAbsolutePath()); }
    public String getPath() { return s.startsWith("file://") ? s.substring(7) : java.net.URI.create(s).getPath(); }
    public String getHost() { return java.net.URI.create(s).getHost(); }
    public String getLastPathSegment() { String p = getPath(); return p.substring(p.lastIndexOf('/') + 1); }
    public String toString() { return s; }
}
