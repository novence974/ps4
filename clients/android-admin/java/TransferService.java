package com.upsl.admin;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.os.Build;
import android.os.IBinder;
import android.os.PowerManager;

/**
 * Foreground service that runs queued uploads one after another and shows their progress
 * in a notification. Declared in the manifest with foregroundServiceType="dataSync".
 */
public class TransferService extends Service {
    private static final String CHANNEL = "uploads";
    private static final int NOTIFICATION_ID = 4101;
    private volatile boolean running;
    private PowerManager.WakeLock wakeLock;

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        Notification n = build("تحضير الرفع…", 0, true);
        if (Build.VERSION.SDK_INT >= 29) {
            startForeground(NOTIFICATION_ID, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC);
        } else {
            startForeground(NOTIFICATION_ID, n);
        }
        if (!running) {
            running = true;
            PowerManager pm = (PowerManager) getSystemService(Context.POWER_SERVICE);
            if (pm != null) {
                wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "upsl:uploads");
                wakeLock.setReferenceCounted(false);
                wakeLock.acquire(6L * 60 * 60 * 1000);
            }
            new Thread(new Runnable() {
                @Override
                public void run() {
                    loop();
                }
            }, "upsl-uploads").start();
        }
        return START_NOT_STICKY;
    }

    private void loop() {
        final Transfers tr = Transfers.get(this);
        Transfers.Listener progress = new Transfers.Listener() {
            @Override
            public void changed(Transfers.Task t) {
                if (t.active()) {
                    String line = t.name + " — " + Math.round(t.fraction() * 100) + "٪";
                    if (Transfers.PROCESSING.equals(t.status) || Transfers.FINISHING.equals(t.status)) {
                        line = t.name + " — جارٍ التحقق والتجميع على الخادم";
                    }
                    notifyProgress(line, (int) (t.fraction() * 1000), Transfers.PROCESSING.equals(t.status));
                }
            }
        };
        tr.addListener(progress);
        try {
            Transfers.Task t;
            while ((t = tr.next()) != null) {
                tr.run(t);
                if (Transfers.DONE.equals(t.status)) {
                    finishedNotice("اكتمل رفع " + t.name, t.title);
                } else if (Transfers.FAILED.equals(t.status)) {
                    finishedNotice("فشل رفع " + t.name, t.error);
                }
            }
        } finally {
            tr.removeListener(progress);
            running = false;
            if (wakeLock != null && wakeLock.isHeld()) {
                wakeLock.release();
            }
            stopForeground(true);
            stopSelf();
        }
    }

    private void ensureChannel(NotificationManager nm) {
        if (Build.VERSION.SDK_INT >= 26 && nm.getNotificationChannel(CHANNEL) == null) {
            NotificationChannel ch = new NotificationChannel(CHANNEL, "رفع الملفات", NotificationManager.IMPORTANCE_LOW);
            ch.setDescription("تقدم رفع ملفات الألعاب والوسائط إلى الخادم");
            nm.createNotificationChannel(ch);
        }
    }

    private Notification build(String text, int progress, boolean indeterminate) {
        NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        ensureChannel(nm);
        Intent open = new Intent(this, UploadsActivity.class);
        open.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        PendingIntent pi = PendingIntent.getActivity(this, 0, open, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        Notification.Builder b = Build.VERSION.SDK_INT >= 26 ? new Notification.Builder(this, CHANNEL) : new Notification.Builder(this);
        b.setSmallIcon(R.drawable.ic_cloud_upload)
                .setContentTitle("رفع الملفات")
                .setContentText(text)
                .setOngoing(true)
                .setOnlyAlertOnce(true)
                .setContentIntent(pi)
                .setProgress(1000, progress, indeterminate);
        return b.build();
    }

    private void notifyProgress(String text, int progress, boolean indeterminate) {
        NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        if (nm != null && running) {
            nm.notify(NOTIFICATION_ID, build(text, progress, indeterminate));
        }
    }

    private void finishedNotice(String title, String text) {
        NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        if (nm == null) {
            return;
        }
        ensureChannel(nm);
        Intent open = new Intent(this, UploadsActivity.class);
        open.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        PendingIntent pi = PendingIntent.getActivity(this, 1, open, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        Notification.Builder b = Build.VERSION.SDK_INT >= 26 ? new Notification.Builder(this, CHANNEL) : new Notification.Builder(this);
        b.setSmallIcon(R.drawable.ic_cloud_upload).setContentTitle(title).setContentText(text == null ? "" : text)
                .setAutoCancel(true).setContentIntent(pi);
        nm.notify((int) (System.currentTimeMillis() % 100000) + 5000, b.build());
    }
}
