package com.upsl.admin;

import java.util.ArrayList;
import java.util.List;

/** Arabic labels for the server's enum values (kept identical to the website). */
public final class Labels {
    private Labels() {
    }

    public static final String[][] REGIONS = {
            {"GLOBAL", "عالمي"}, {"EUR", "أوروبا"}, {"USA", "أمريكا"}, {"JPN", "اليابان"}, {"ASIA", "آسيا"}, {"MENA", "الشرق الأوسط"}};
    public static final String[][] FILE_TYPES = {
            {"game", "ملف اللعبة"}, {"update", "تحديث"}, {"dlc", "محتوى إضافي"}, {"data", "ملف بيانات"}, {"save", "ملف حفظ"}, {"other", "ملف آخر"}};
    public static final String[][] ACCESS = {
            {"public", "للجميع"}, {"registered", "المسجلون"}, {"verified", "الحسابات المؤكدة"}, {"moderator", "المشرفون فأعلى"}, {"admin", "المدراء فقط"}};
    public static final String[][] COMPAT = {
            {"perfect", "مثالي"}, {"playable", "قابل للعب"}, {"ingame", "يعمل مع مشاكل"}, {"menus", "القوائم فقط"},
            {"boots", "يقلع فقط"}, {"nothing", "لا يعمل"}, {"unknown", "غير مختبر"}};
    public static final String[][] LINK_TYPES = {
            {"official_store", "المتجر الرسمي"}, {"publisher", "موقع الناشر"}, {"authorized_mirror", "مصدر مصرح به"}, {"info", "معلومات"}};
    public static final String[][] UPDATE_TYPES = {
            {"game_added", "لعبة جديدة"}, {"game_updated", "تحديث لعبة"}, {"patch", "إصدار/تصحيح"}, {"announcement", "إعلان"}, {"maintenance", "صيانة"}};
    public static final String[][] FILES_STATUS = {
            {"available", "الملفات متوفرة"}, {"unavailable", "غير متوفرة حالياً"}, {"coming_soon", "قريباً"}};
    public static final String[][] USER_STATUS = {{"active", "نشط"}, {"suspended", "موقوف"}};
    public static final String[][] UPLOAD_STATUS = {
            {"uploading", "قيد الرفع"}, {"processing", "قيد المعالجة"}, {"completed", "مكتمل"}, {"failed", "فشل"}, {"cancelled", "ملغى"}};
    public static final String[][] UPLOAD_PURPOSES = {
            {"game_file", "ملف لعبة"}, {"cover", "غلاف"}, {"banner", "خلفية"}, {"screenshot", "لقطة شاشة"}, {"trailer", "فيديو تعريفي"},
            {"platform_logo", "شعار جهاز"}, {"platform_image", "صورة جهاز"}, {"avatar", "صورة شخصية"}, {"music", "موسيقى الموقع"},
            {"backup", "نسخة احتياطية"}, {"import", "ملف استيراد"}};
    public static final String[][] CATEGORY_ICONS = {
            {"bolt", "برق"}, {"compass", "بوصلة"}, {"globe", "كرة أرضية"}, {"ghost", "شبح"}, {"flag", "علم"}, {"ball", "كرة"},
            {"trophy", "كأس"}, {"fist", "قبضة"}, {"home", "منزل"}, {"star", "نجمة"}, {"sword", "سيف"}, {"chess", "شطرنج"}};
    public static final String[][] LANGUAGES = {
            {"ar", "العربية"}, {"en", "English"}, {"fr", "Français"}, {"de", "Deutsch"}, {"es", "Español"}, {"it", "Italiano"},
            {"pt", "Português"}, {"ru", "Русский"}, {"ja", "日本語"}, {"ko", "한국어"}, {"zh", "中文"}, {"tr", "Türkçe"},
            {"pl", "Polski"}, {"nl", "Nederlands"}};
    public static final String[][] THEMES = {{"dark", "داكن"}, {"light", "فاتح"}, {"auto", "حسب الجهاز"}};
    public static final String[][] MAIL_DRIVERS = {{"log", "سجل فقط (بدون إرسال)"}, {"smtp", "SMTP"}, {"mail", "دالة mail في PHP"}};
    public static final String[][] MAIL_ENCRYPTION = {{"tls", "TLS"}, {"ssl", "SSL"}, {"none", "بدون"}};
    public static final String[][] BACKUP_FREQUENCY = {{"off", "متوقف"}, {"daily", "يومي"}, {"weekly", "أسبوعي"}};
    public static final String[][] LOG_LEVELS = {{"", "الكل"}, {"error", "أخطاء"}, {"warning", "تحذيرات"}, {"info", "معلومات"}};

    public static String of(String[][] map, String key) {
        if (key == null) {
            return "—";
        }
        for (String[] e : map) {
            if (e[0].equals(key)) {
                return e[1];
            }
        }
        return key;
    }

    public static List<String> keys(String[][] map) {
        List<String> out = new ArrayList<String>();
        for (String[] e : map) {
            out.add(e[0]);
        }
        return out;
    }

    public static List<String> labels(String[][] map) {
        List<String> out = new ArrayList<String>();
        for (String[] e : map) {
            out.add(e[1]);
        }
        return out;
    }

    public static int compatColor(String status) {
        if ("perfect".equals(status) || "playable".equals(status)) {
            return Ui.OK;
        }
        if ("ingame".equals(status) || "menus".equals(status) || "boots".equals(status)) {
            return Ui.WARN;
        }
        if ("nothing".equals(status)) {
            return Ui.BAD;
        }
        return Ui.MIST;
    }

    public static int uploadColor(String status) {
        if ("completed".equals(status)) {
            return Ui.OK;
        }
        if ("failed".equals(status)) {
            return Ui.BAD;
        }
        if ("cancelled".equals(status)) {
            return Ui.FAINT;
        }
        if ("processing".equals(status)) {
            return Ui.WARN;
        }
        return Ui.ACCENT2;
    }

    public static int categoryIcon(String icon) {
        if (icon == null) {
            return R.drawable.ic_tag;
        }
        switch (icon) {
            case "bolt":
                return R.drawable.ic_cat_bolt;
            case "compass":
                return R.drawable.ic_cat_compass;
            case "globe":
                return R.drawable.ic_cat_globe;
            case "ghost":
                return R.drawable.ic_cat_ghost;
            case "flag":
                return R.drawable.ic_cat_flag;
            case "ball":
                return R.drawable.ic_cat_ball;
            case "trophy":
                return R.drawable.ic_cat_trophy;
            case "fist":
                return R.drawable.ic_cat_fist;
            case "home":
                return R.drawable.ic_cat_home;
            case "star":
                return R.drawable.ic_cat_star;
            case "sword":
                return R.drawable.ic_cat_sword;
            case "chess":
                return R.drawable.ic_cat_chess;
            default:
                return R.drawable.ic_tag;
        }
    }

    /** Human label for a settings key ("site.name" → "اسم الموقع"). */
    public static String setting(String key) {
        String[][] map = {
                {"site.name", "اسم الموقع"}, {"site.tagline", "الوصف المختصر"}, {"site.default_theme", "المظهر الافتراضي"},
                {"site.cdn_url", "رابط CDN للصور"}, {"site.per_page", "عدد الألعاب في الصفحة"}, {"site.maintenance", "وضع الصيانة"},
                {"site.maintenance_message", "رسالة الصيانة"}, {"updates.auto_on_publish", "نشر تحديث تلقائي عند نشر لعبة"},
                {"music.enabled", "الموسيقى الخلفية"}, {"music.default_volume", "مستوى الصوت الافتراضي"}, {"sfx.enabled", "مؤثرات الواجهة الصوتية"},
                {"mail.driver", "طريقة إرسال البريد"}, {"mail.host", "خادم SMTP"}, {"mail.port", "منفذ SMTP"}, {"mail.encryption", "التشفير"},
                {"mail.username", "اسم مستخدم SMTP"}, {"mail.password", "كلمة مرور SMTP"}, {"mail.from_address", "بريد المرسل"},
                {"mail.from_name", "اسم المرسل"},
                {"auth.registration_open", "السماح بإنشاء حسابات جديدة"}, {"auth.email_verification", "تأكيد البريد قبل التنزيل"},
                {"auth.password_min", "أقل طول لكلمة المرور"}, {"auth.max_attempts", "محاولات الدخول قبل القفل"},
                {"auth.lockout_minutes", "مدة القفل (دقائق)"}, {"auth.session_idle_minutes", "إنهاء الجلسة بعد خمول (دقائق)"},
                {"uploads.allowed_extensions", "امتدادات ملفات الألعاب المسموحة"}, {"uploads.max_file_gb", "أقصى حجم للملف (GB)"},
                {"uploads.image_max_mb", "أقصى حجم للصورة (MB)"}, {"uploads.chunk_size_mb", "حجم الجزء عند الرفع (MB)"},
                {"uploads.sync_assemble_limit_mb", "تجميع مباشر حتى (MB)"}, {"downloads.link_ttl_minutes", "صلاحية رابط التنزيل (دقائق)"},
                {"downloads.max_links_per_hour", "روابط التنزيل لكل مستخدم في الساعة"}, {"storage.default_disk", "قرص التخزين الافتراضي"},
                {"api.enabled", "تفعيل واجهة API"}, {"api.token_ttl_days", "صلاحية رمز الدخول (أيام)"},
                {"api.rate_limit_per_minute", "حد الطلبات في الدقيقة"}, {"api.cors_origins", "النطاقات المسموحة (CORS)"},
                {"backups.frequency", "النسخ التلقائي"}, {"backups.keep", "عدد النسخ المحفوظة"}, {"backups.include_media", "تضمين الصور والوسائط"},
        };
        return of(map, key);
    }

    public static String[][] settingOptions(String key) {
        if ("site.default_theme".equals(key)) {
            return THEMES;
        }
        if ("mail.driver".equals(key)) {
            return MAIL_DRIVERS;
        }
        if ("mail.encryption".equals(key)) {
            return MAIL_ENCRYPTION;
        }
        if ("backups.frequency".equals(key)) {
            return BACKUP_FREQUENCY;
        }
        return null;
    }

    public static String settingsGroup(String g) {
        if ("general".equals(g)) {
            return "عام";
        }
        if ("security".equals(g)) {
            return "الأمان والرفع";
        }
        if ("api".equals(g)) {
            return "واجهة API";
        }
        if ("backups".equals(g)) {
            return "النسخ الاحتياطي";
        }
        return g;
    }
}
