package android.content;

import java.util.HashMap;
import java.util.Map;

/** JVM test double: just enough of Context for Api, Session and Transfers. */
public class Context {
    public static final int MODE_PRIVATE = 0;
    public static final String POWER_SERVICE = "power";
    private static final Map<String, SharedPreferences> PREFS = new HashMap<String, SharedPreferences>();
    private final ContentResolver resolver = new ContentResolver();
    public int servicesStarted;

    public Context getApplicationContext() { return this; }

    public synchronized SharedPreferences getSharedPreferences(String name, int mode) {
        SharedPreferences p = PREFS.get(name);
        if (p == null) { p = new SharedPreferences.Memory(); PREFS.put(name, p); }
        return p;
    }

    public ContentResolver getContentResolver() { return resolver; }

    public ComponentName startService(Intent i) { servicesStarted++; return null; }

    public ComponentName startForegroundService(Intent i) { servicesStarted++; return null; }
}
