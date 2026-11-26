package android.os;

import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/** Posts to one "main" thread, like the Android main looper. */
public class Handler {
    public static final ExecutorService MAIN = Executors.newSingleThreadExecutor();
    public Handler() { }
    public Handler(Looper l) { }
    public boolean post(Runnable r) { MAIN.execute(r); return true; }
}
