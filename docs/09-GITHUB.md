# تشغيل المكتبة على GitHub

GitHub يقدّم طريقتين، ولكل واحدة حدود:

| الطريقة | ماذا يعمل | ماذا لا يعمل | الرابط |
|---|---|---|---|
| **GitHub Pages** (دائم ومجاني) | كل الصفحات العامة: الرئيسية، الأجهزة، التصنيفات، صفحات الألعاب بالصور، التحديثات | تسجيل الدخول، التنزيل، البحث والفلاتر، لوحة الإدارة، الـ API | `https://اسمك.github.io/اسم-المستودع/` |
| **GitHub Codespaces** (الموقع الكامل) | كل شيء: PHP وقاعدة البيانات والإدارة والـ API | يتوقف عند إغلاق الـ Codespace، وساعات الاستخدام المجانية محدودة شهرياً | رابط مؤقت `https://…-8080.app.github.dev` |

السبب: GitHub Pages يستضيف ملفات ثابتة فقط ولا يشغّل PHP أو MySQL. لموقع كامل دائم تحتاج خادماً (VPS أو استضافة PHP) — انظر آخر الملف.

---

## 1. إنشاء المستودع ورفع المشروع

1. سجّل في https://github.com ثم اضغط **New repository**.
2. اكتب الاسم مثلاً `ultimate-ps-library`، اختر **Public** (Pages مجاني للمستودعات العامة في الحساب المجاني)، ولا تضف README.
3. ارفع الملفات بإحدى طريقتين:
   - **من المتصفح:** فك ضغط الـ zip، ثم في صفحة المستودع **uploading an existing file** واسحب محتوى المجلد (بما فيه `.github` و`.devcontainer`). الواجهة لا ترفع أكثر من 100 ملف في المرة، فارفع على دفعات.
   - **من الطرفية (أسهل):**
     ```bash
     cd ultimate-ps-library
     git init -b main
     git add .
     git commit -m "Ultimate PS Library"
     git remote add origin https://github.com/اسمك/ultimate-ps-library.git
     git push -u origin main
     ```
   ملف `.gitignore` يمنع رفع `config.php` و`.env` وملفات التخزين.

## 2. تفعيل GitHub Pages

1. في المستودع: **Settings → Pages**.
2. في **Source** اختر **GitHub Actions**.
3. افتح تبويب **Actions**؛ سيعمل "Publish site to GitHub Pages" تلقائياً مع كل push إلى `main` (أو شغّله يدوياً بـ **Run workflow**).
4. بعد 3–5 دقائق يظهر الرابط في **Settings → Pages** وفي نتيجة الـ workflow.

ما يفعله الـ workflow (`.github/workflows/pages.yml`): يشغّل PHP 8.3 وMariaDB مؤقتاً، يثبّت المكتبة مع كتالوج العرض (31 لعبة)، يصدّر الصفحات العامة بـ `tools/export_static.sh`، ثم ينشرها. كلمة مرور المدير المؤقتة عشوائية وتُحذف مع الخادم.

**نطاق خاص (اختياري):** Settings → Pages → Custom domain، ثم سجل CNAME عند مزوّد النطاق يشير إلى `اسمك.github.io`. إذا استخدمت نطاقاً خاصاً على الجذر عدّل خطوة "Work out the site path" لتعطي `base=""`.

**لعرض ألعابك بدل كتالوج العرض:** نسخة Pages تُبنى من بيانات العرض. لنشر كتالوجك الحقيقي شغّل التصدير من خادمك ثم ارفع الناتج:
```bash
tools/export_static.sh https://موقعك _site /ultimate-ps-library
```
وانشر مجلد `_site` (مثلاً إلى فرع `gh-pages`).

## 3. الموقع الكامل على Codespaces

1. في صفحة المستودع: **Code → Codespaces → Create codespace on main**.
2. ينتظر دقائق في أول مرة (يبني الحاويات ويثبّت المكتبة).
3. يطبع الطرفية سطراً مثل `Admin login: admin / Admin-xxxxxx` — احفظه (موجود أيضاً في `.env`).
4. افتح تبويب **Ports** واضغط على رابط المنفذ 8080. للمشاركة مع غيرك: كليك يمين → **Port Visibility → Public**.

ملاحظة: الـ Codespace يتوقف تلقائياً بعد فترة خمول، والبيانات تبقى حتى تحذفه.

## 4. موقع كامل دائم (خارج GitHub)

على أي خادم Linux فيه Docker:
```bash
git clone https://github.com/اسمك/ultimate-ps-library.git && cd ultimate-ps-library
cp .env.example .env      # غيّر كلمات المرور و APP_URL
docker compose up -d --build
```
الموقع على المنفذ 8080؛ ضع أمامه nginx أو Caddy مع HTTPS (مثال nginx في `server/deploy/nginx.conf`). بدون Docker اتبع `docs/01-INSTALL.md`.

بعد ذلك وجّه تطبيق الإدارة (Android) وتطبيق PS4 إلى رابط هذا الخادم.

## ما تم اختباره

- `tools/export_static.sh`: صدّر 52 صفحة، وفُحصت الروابط والصور والخطوط من مسار `/ultimate-ps-library/` بمتصفح.
- `docker compose`: بُني وشغّل هنا؛ التثبيت التلقائي، الصفحات، الـ API، تسجيل دخول المدير، وإعادة التشغيل مع بقاء البيانات.
- ملف `pages.yml` نفسه لم يُشغَّل على GitHub بعد (يحتاج مستودعك)، لكنه ينفّذ نفس الخطوات المختبرة. Codespaces كذلك لم يُجرَّب على GitHub.
