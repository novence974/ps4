package android.content;

import java.util.concurrent.ConcurrentHashMap;

public interface SharedPreferences {
    String getString(String key, String def);
    boolean getBoolean(String key, boolean def);
    Editor edit();

    interface Editor {
        Editor putString(String key, String value);
        Editor putBoolean(String key, boolean value);
        Editor remove(String key);
        void apply();
        boolean commit();
    }

    /** In-memory implementation used by the JVM tests. */
    final class Memory implements SharedPreferences {
        private final ConcurrentHashMap<String, Object> map = new ConcurrentHashMap<String, Object>();
        public String getString(String key, String def) { Object v = map.get(key); return v == null ? def : (String) v; }
        public boolean getBoolean(String key, boolean def) { Object v = map.get(key); return v == null ? def : (Boolean) v; }
        public Editor edit() {
            return new Editor() {
                public Editor putString(String k, String v) { if (v == null) map.remove(k); else map.put(k, v); return this; }
                public Editor putBoolean(String k, boolean v) { map.put(k, v); return this; }
                public Editor remove(String k) { map.remove(k); return this; }
                public void apply() { }
                public boolean commit() { return true; }
            };
        }
    }
}
