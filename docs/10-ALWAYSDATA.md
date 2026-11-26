# تشغيل الموقع الكامل على alwaysdata (مجاناً، بدون بطاقة)

الحساب: `novence` ← الموقع سيكون على **https://novence.alwaysdata.net**

كل الخطوات من لوحة alwaysdata على https://admin.alwaysdata.com ، ثم أمر واحد عبر SSH.

## 1. قاعدة البيانات

**Databases ← MySQL ← Add a database**

- Name: `novence_upsl`
- في نفس الصفحة: أعطِ المستخدم `novence` كل الصلاحيات (All rights) على القاعدة.

**Databases ← MySQL ← Users**: إذا لم تكن تعرف كلمة مرور المستخدم `novence`، عدّله واكتب كلمة مرور جديدة واحفظها (ستحتاجها في الخطوة 3).

## 2. تفعيل SSH

**Remote access ← SSH ← عدّل المستخدم `novence`**

- فعّل **Enable password-based login**.

عنوان الاتصال: `ssh-novence.alwaysdata.net` — المستخدم `novence`.

من الجوال يمكنك استخدام تطبيق Termius (Android/iPhone)، أو من اللوحة مباشرة: **Remote access ← SSH ← Web SSH**.

## 3. التثبيت (أمر واحد)

بعد الاتصال عبر SSH انسخ هذا:

```bash
cd ~ && git clone --depth 1 https://github.com/novence974/ps4.git && bash ~/ps4/server/deploy/alwaysdata/install.sh
```

ثم اجعل مجلد `www` يشير إلى مجلد الموقع العام (يغني عن كتابة مسار طويل في اللوحة):

```bash
mv ~/www ~/www-old && ln -s ~/ps4/server/public ~/www
```

سيسألك عن:

| السؤال | ماذا تكتب |
|---|---|
| اسم قاعدة البيانات | اضغط Enter (`novence_upsl`) |
| مستخدم قاعدة البيانات | اضغط Enter (`novence`) |
| كلمة مرور قاعدة البيانات | كلمة مرور الخطوة 1 (لا تظهر أثناء الكتابة) |
| اسم مستخدم المدير وبريده وكلمة مروره | بيانات دخولك للوحة التحكم ولتطبيق الإدارة |

كلمات المرور لا تُكتب في سطر الأوامر ولا تُحفظ في السجل.

## 4. ربط الموقع بالمجلد

**Web ← Sites ← عدّل `novence.alwaysdata.net`**

- Type: **PHP**
- PHP version: **8.3**
- Root directory: الجزء `/home/novence/` ثابت، واكتب بعده **`www`** فقط
- احفظ.

## 5. المهام المجدولة (النسخ الاحتياطي، التنظيف، طابور المهام)

**Advanced ← Scheduled tasks ← Add a scheduled task**

- Type: **Execute the command**
- Command: `php ~/ps4/server/bin/cron.php`
- Frequency: كل 5 دقائق — `*/5 * * * *`

## فرض HTTPS

الخادم يتعرّف على HTTPS عند alwaysdata، فيكفي تفعيل التحويل من إعدادات المكتبة:

```bash
sed -i "s/'force_https' => false/'force_https' => true/" ~/ps4/server/config/config.php
```

## 6. التجربة

- افتح https://novence.alwaysdata.net وسجّل الدخول.
- في تطبيق الإدارة: الخادم `https://novence.alwaysdata.net` ثم بيانات المدير.
- افتح «سجل الموقع» في التطبيق لترى الدخول مسجلاً.

## التحديث لاحقاً

عندما أضيف ميزات جديدة على GitHub:

```bash
bash ~/ps4/server/deploy/alwaysdata/update.sh
```

لا يمس الإعدادات ولا قاعدة البيانات ولا الصور المرفوعة.

## حدود الخطة المجانية

- للاستخدام الشخصي فقط (بدون إعلانات أو بيع).
- المساحة صغيرة: استخدم **روابط الصور** بدل رفعها، وروابط الملفات بدل رفع ملفات الألعاب.
- الذاكرة 256 MB: تكفي لزيارات خفيفة ومتوسطة.
- الرفع المباشر للملفات الكبيرة غير مناسب لهذه الخطة.
