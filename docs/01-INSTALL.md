# دليل التثبيت خطوة بخطوة

يشرح هذا الدليل تثبيت الخادم المركزي (المرحلة الأولى) على خادم Linux بثلاث طرق مختبرة:
- nginx (موصى به).
- Apache بجذر `server/public`.
- استضافة مشتركة لا تسمح بتغيير جذر الموقع.

## 1. المتطلبات

| العنصر | الحد الأدنى | ملاحظات |
|---|---|---|
| PHP | 8.2 | اختُبر على 8.3. الإضافات: `pdo_mysql`, `gd` (مع WebP)، `mbstring`, `fileinfo`, `curl`, `openssl`, `zip` (للنسخ الكاملة) |
| قاعدة البيانات | MySQL 8.0 أو MariaDB 10.5 | اختُبر على MariaDB 10.11 بوضع SQL صارم (مطابق لإعدادات MySQL 8) |
| خادم الويب | nginx 1.18+ أو Apache 2.4 | مع PHP-FPM |
| الكاش | اختياري | Redis (`php-redis`) يُستخدم تلقائياً إن وُجد، وإلا كاش الملفات. لكل تثبيت بادئة مفاتيح خاصة، فيمكن لعدة مكتبات مشاركة خادم Redis واحد |
| المساحة | حسب مكتبتك | ملفات الألعاب في `server/storage/files` أو على أقراص إضافية (NAS) |

مثال على Ubuntu 24.04:

```bash
sudo apt install nginx mariadb-server redis-server php8.3-fpm php8.3-mysql php8.3-gd \
  php8.3-mbstring php8.3-curl php8.3-zip php8.3-redis php8.3-xml
```

## 2. رفع الملفات والصلاحيات

```bash
sudo mkdir -p /var/www/ultimate-ps-library
sudo cp -r server /var/www/ultimate-ps-library/
cd /var/www/ultimate-ps-library/server
sudo chown -R www-data:www-data storage public/media config
sudo find storage public/media -type d -exec chmod 775 {} \;
```

يجب أن يملك مستخدم PHP صلاحية الكتابة على المجلدات الثلاثة: `storage/` و `public/media/` و `config/`. المجلد `config/` يحتاج صلاحية الكتابة أثناء التثبيت فقط.

## 3. إنشاء قاعدة البيانات

```sql
CREATE DATABASE upsl CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'upsl'@'localhost' IDENTIFIED BY 'Db-Pass-Change-Me';
GRANT ALL PRIVILEGES ON upsl.* TO 'upsl'@'localhost';
FLUSH PRIVILEGES;
```

## 4. التثبيت

اختر طريقة واحدة. كل طريقة تُنشئ:
- الجداول الـ25 والأدوار والصلاحيات والأجهزة الخمسة والتصنيفات الاثني عشر.
- حساب المدير الرئيسي.
- الملف `config/config.php` بمفتاح تشفير عشوائي.
- ملف القفل `storage/installed.lock`.

### أ) معالج الويب

افتح `https://موقعك/install.php`. يعرض المعالج فحص المتطلبات ثم نموذجاً لقاعدة البيانات ورابط الموقع وحساب المدير. خيار «الألعاب التجريبية» يضيف 31 لعبة خيالية بأغلفة أصلية و8 ملفات صغيرة لتجربة التنزيل، ويمكن حذفها لاحقاً من لوحة التحكم.

بعد التثبيت يتعطل المعالج تلقائياً ما دام `config.php` أو `installed.lock` موجوداً. يمكنك حذف `public/install.php` نهائياً لمزيد من الأمان.

### ب) سطر الأوامر

```bash
cd /var/www/ultimate-ps-library/server
sudo -u www-data php bin/install.php --db-host=127.0.0.1 --db-name=upsl --db-user=upsl --db-pass='…' \
  --url=https://library.example.com --admin-user=admin --admin-email=you@example.com \
  --admin-pass='Strong-Pass-2026' --timezone=Asia/Riyadh [--demo]
```

بدون معاملات يعمل الأمر تفاعلياً ويسأل عن كل قيمة. التثبيت مع البيانات التجريبية يستغرق نحو 30 ثانية.

### ج) يدوياً

1. استورد الملفين `database/schema.sql` ثم `database/seed.sql`.
2. انسخ `config/config.example.php` إلى `config/config.php` وعدّل قيمه، وولّد المفتاح بالأمر:
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```
3. أنشئ حساب المدير بالأمر:
   ```bash
   php bin/create-admin.php --user=admin --email=you@example.com --pass='Strong-Pass-2026'
   ```

## 5. إعداد خادم الويب

### nginx (موصى به)

استخدم الملف `deploy/nginx.conf` بعد تعديل اسم النطاق والمسارات ومقبس PHP-FPM. أهم ما فيه:

- `root` يشير إلى `server/public` فقط. بذلك تبقى المجلدات `app/` و `config/` و `storage/` خارج متناول الويب.
- `client_max_body_size 64m` و `fastcgi_request_buffering off` لاستقبال أجزاء الرفع.
- منع تنفيذ أي سكربت داخل `/media/`.
- موقع داخلي `/protected-files/` للتنزيل السريع عبر X-Accel-Redirect. لتفعيله اضبط في `config.php`:
  ```php
  'x_sendfile' => 'nginx'
  ```
  وعندها يرسل nginx الملف بنفسه بعد أن يتحقق PHP من الصلاحية والتوقيع.

```bash
sudo cp deploy/nginx.conf /etc/nginx/sites-available/upsl
sudo ln -s /etc/nginx/sites-available/upsl /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### Apache 2.4 بجذر `public/`

استخدم الملف `deploy/apache-vhost.conf` وفعّل الوحدات المطلوبة:

```bash
sudo a2enmod rewrite headers proxy_fcgi setenvif ssl deflate
```

الملف `public/.htaccess` يتولى توجيه الطلبات إلى `index.php` وتمرير ترويسة `Authorization` التي تحتاجها رموز API.

**مستحسن على Apache:** عند تمرير الملف عبر PHP-FPM يحذف Apache ترويسة `Content-Length` ويرسل الاستجابة مجزأة (chunked). التنزيل والاستكمال يعملان رغم ذلك، لكن المتصفح لا يعرف الحجم مسبقاً. لإرسال الحجم الصحيح وتنزيل أسرع:

1. ثبّت `libapache2-mod-xsendfile`.
2. أضف إلى الـ VirtualHost:
   ```apache
   XSendFile On
   XSendFilePath /var/www/ultimate-ps-library/server/storage/files
   ```
3. اضبط في `config.php`:
   ```php
   'x_sendfile' => 'apache'
   ```

اختُبر هذا الوضع بتنزيل كامل وطلبات Range.

### استضافة مشتركة (cPanel وما شابه)

إن لم تستطع جعل جذر الموقع `server/public`، ارفع **محتوى** مجلد `server/` كاملاً إلى `public_html` أو إلى مجلد فرعي داخله:
- الملف `server/.htaccess` يحوّل كل الطلبات إلى `public/`، فتبقى الروابط نظيفة بدون `/public`.
- يمنع الملف نفسه الوصول إلى `app` و `config` و `database` و `storage` و `bin` و `tests` و `deploy`، ولكل مجلد منها `.htaccess` يرفض الوصول أيضاً.

تحقق بعد الرفع أن الروابط التالية تعيد 403:
- `/config/config.php`
- `/storage/logs/`
- `/app/routes.php`

## 6. إعدادات PHP

القيم المقترحة في `deploy/php.ini.recommended`:

| الإعداد | القيمة المقترحة |
|---|---|
| `upload_max_filesize` | `32M` |
| `post_max_size` | `64M` |
| `memory_limit` | `256M` |
| `max_execution_time` | `300` |

حجم جزء الرفع (5 ميغابايت افتراضياً) يُضبط من «الأمان» في لوحة التحكم، ولا يتجاوز تلقائياً 90% من `post_max_size`.

## 7. المهام الخلفية (ضروري)

```cron
* * * * * www-data php /var/www/ultimate-ps-library/server/bin/cron.php >> /var/www/ultimate-ps-library/server/storage/logs/cron.log 2>&1
```

يعمل `cron.php` كل دقيقة ويتولى ما يلي:
- معالجة طابور المهام، مثل تجميع الملفات الكبيرة بعد رفعها.
- إنهاء عمليات الرفع المتروكة بعد 48 ساعة وحذف أجزائها.
- النسخ الاحتياطي المجدول وحذف النسخ القديمة.
- تنظيف السجلات القديمة.

إن رفعت ملفات ضخمة كثيراً، شغّل العامل الدائم `bin/worker.php` كخدمة systemd باستخدام `deploy/upsl-worker.service`، فيبدأ التجميع فوراً بدل انتظار الدقيقة التالية.

## 8. HTTPS والوكيل العكسي

- فعّل HTTPS (مثلاً عبر Let's Encrypt). كعكة الجلسة تصبح `Secure` تلقائياً عند HTTPS، وتُرسل ترويسة HSTS.
- خلف Cloudflare أو موازن حمل، أضف عناوين الوكيل إلى `security.trusted_proxies` في `config.php`. بذلك يُقرأ عنوان الزائر الحقيقي من `X-Forwarded-For` لحدود الطلبات وسجل النشاط. لا تضف عناوين لا تثق بها.
- لفرض HTTPS من التطبيق نفسه:
  ```php
  'security' => ['force_https' => true]
  ```

## 9. البريد الإلكتروني

افتراضياً يُكتب كل بريد (تأكيد الحساب، استعادة كلمة المرور) إلى ملفات في `storage/mail/`، وهذا يكفي للتجربة. للإرسال الفعلي:
1. افتح «لوحة التحكم ← الإعدادات» واختر SMTP.
2. أدخل الخادم والمنفذ والتشفير وبيانات الدخول.

كلمة مرور SMTP تُخزَّن مشفّرة في قاعدة البيانات.

## 10. الملفات الضخمة والأقراص الإضافية

- يمكن رفع الملفات من المتصفح حتى الحد المضبوط (200 GB افتراضياً) بفضل الرفع المجزأ.
- لملفات نسختها مسبقاً إلى الخادم (SFTP أو rsync)، استخدم الأمر التالي، فيحسب SHA-256 ويسجل الملف دون إعادة رفعه:
  ```bash
  php bin/register-file.php --game=12 --file=/data/incoming/game.pkg --type=game --access=registered --move
  ```
- لإضافة قرص NAS أو قرص ثانٍ:
  ```php
  'storage' => ['disks' => ['local' => …, 'nas' => '/mnt/nas/ps-library']]
  ```
  ثم اختر القرص الافتراضي من صفحة «الأمان». الملفات الكبيرة لا تُحفظ في قاعدة البيانات أبداً، بل اسم القرص والمسار والحجم والبصمة فقط.

## 11. قائمة التحقق بعد التثبيت

- [ ] `debug` = `false` في `config.php` (المثبّت يضبطها هكذا).
- [ ] لوحة «حالة الخادم» في لوحة المعلومات تُظهر قاعدة البيانات والكاش والطابور بحالة سليمة.
- [ ] مهمة Cron تعمل. يظهر وقت آخر تشغيل لها في اللوحة نفسها.
- [ ] جرّب رفع ملف صغير ثم تنزيله من حساب مستخدم عادي.
- [ ] اضبط النسخ الاحتياطي المجدول من «النسخ الاحتياطي».
- [ ] احذف الألعاب التجريبية إن لم تعد تحتاجها. كلها برمز منتج يبدأ بـ `DEMO-`.
- [ ] اختيارياً احذف `public/install.php`.

## 12. التحديث إلى إصدار أحدث

1. خذ نسخة احتياطية كاملة من «النسخ الاحتياطي».
2. استبدل `app/` و `public/assets` و `bin/` و `database/`.
3. لا تلمس `config/config.php` ولا `storage/` ولا `public/media/`.
4. امسح الكاش من صفحة «التخزين».

## 13. حل المشكلات الشائعة

| العَرَض | السبب والحل |
|---|---|
| خطأ 413 أثناء الرفع | ارفع `client_max_body_size` في nginx أو `LimitRequestBody` في Apache، و `post_max_size` في PHP |
| الرفع يبقى «قيد المعالجة» | الملف أكبر من حد التجميع الفوري وينتظر الطابور. تأكد أن `cron.php` أو `worker.php` يعمل |
| رموز API تعيد 401 على Apache | تأكد من تفعيل `setenvif` ومن قاعدة `SetEnvIf Authorization` أو `CGIPassAuth On` |
| الصور لا تظهر بصيغة WebP | إضافة GD بلا دعم WebP: ثبّت `php-gd` حديثاً. الصور تُحفظ أيضاً بصيغة JPEG/PNG احتياطاً |
| رسالة «إصدار قاعدة البيانات قديم» | المطلوب MySQL 8.0+ أو MariaDB 10.5+ (يستخدم البحث دوال النوافذ) |
| تفاصيل الأخطاء | `storage/logs/app-YYYY-MM-DD.log` أو صفحة «سجل الأخطاء» في لوحة التحكم |
