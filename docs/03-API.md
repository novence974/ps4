# مرجع واجهة API ‏(v1)

واجهة REST واحدة يستخدمها الموقع وتطبيق Android وتطبيق PS4. كل المسارات تحت:

```
https://موقعك/api/v1
```

مجموعة Postman جاهزة في `docs/postman_collection.json` (66 طلباً):
- استوردها واضبط المتغيرات `base` و `login` و `password`.
- تشغيلها كاملة آمن: ما تُنشئه يُحذف في مجلد Finish.
- الطلبات التي تعدّل بيانات موجودة في مجلد مستقل وتُرسل يدوياً.
- اختُبرت كاملة بأداة newman: 66 طلباً دون أي خطأ، دون أي أثر في البيانات.

## القواعد العامة

**شكل الاستجابة الناجحة:**

```json
{ "ok": true, "data": { … }, "meta": { … } }
```

**شكل الخطأ:**

```json
{ "ok": false, "error": { "code": "validation_failed", "message": "رسالة بالعربية", "fields": { "email": "…" } } }
```

| HTTP | `error.code` | المعنى |
|---|---|---|
| 401 | `unauthenticated` | لا يوجد رمز أو انتهت صلاحيته |
| 403 | `forbidden` | الحساب لا يملك الصلاحية |
| 404 | `not_found` | العنصر غير موجود أو غير منشور |
| 409 | `conflict`، `duplicate_game` | تعارض، مثل لعبة مكررة أو رفع غير مكتمل |
| 413 | `payload_too_large` | الملف أكبر من الحد المسموح |
| 416 | `range_not_satisfiable` | نطاق تنزيل خارج حجم الملف |
| 419 | `csrf_token_mismatch` | طلب بكعكة الجلسة دون ترويسة `X-CSRF-Token` |
| 422 | `validation_failed` | بيانات غير صالحة، والتفاصيل في `fields` |
| 429 | `too_many_requests`، `account_locked` | تجاوز حد الطلبات. انتظر المدة في ترويسة `Retry-After` |
| 503 | `service_unavailable` | صيانة أو API معطلة من لوحة التحكم |

**المصادقة:**
- التطبيقات تستخدم ترويسة الرمز:
  ```
  Authorization: Bearer upsl_…
  ```
  يُحصل على الرمز من `/auth/login` أو `/auth/register`، وصلاحيته 30 يوماً افتراضياً.
- الموقع نفسه يستخدم كعكة الجلسة مع ترويسة `X-CSRF-Token`.

**الصفحات:**
- المعاملات `page` و `per_page` (الحد الأقصى 100).
- الاستجابة تحمل:
  ```json
  "meta": { "page": 1, "per_page": 24, "total": 30, "pages": 2 }
  ```

**الكاش والضغط:**
- استجابات GET تحمل `ETag`. أرسل `If-None-Match` لتحصل على `304` بلا محتوى.
- الاستجابات تُضغط بـ gzip عند إرسال `Accept-Encoding: gzip`.

**حد الطلبات:**
- 120 طلباً في الدقيقة افتراضياً، ويُعدّل من «إعدادات API».
- الترويستان `X-RateLimit-Limit` و `X-RateLimit-Remaining` في كل استجابة.

**الروابط والصور:**
- كل الروابط في الاستجابات مطلقة (تبدأ بـ `https://`).
- كل صورة لها نسخة WebP ونسخة `_jpg` (أو `_png` للشعارات) للأجهزة التي لا تدعم WebP، مثل PS4.

**التواريخ:** بصيغة ISO-8601 وبتوقيت UTC.

---

## 1. المسارات العامة (بدون تسجيل دخول)

### `GET /status`
حالة الخادم للمراقبة وللتحقق من الربط في التطبيقات. يبقى متاحاً حتى لو عُطلت API.

```json
{ "status": "ok", "app": "ULTIMATE PS LIBRARY", "version": "1.0.0", "api_version": "v1",
  "maintenance": false, "registration_open": true, "database": { "ok": true, "latency_ms": 0.7 }, "chunk_size": 5242880 }
```

مع رمز مدير تضاف تفاصيل عن PHP والطابور والإضافات.

### `GET /platforms`
الأجهزة النشطة مرتبة. كل عنصر يحتوي على:
- `id`, `slug`, `name`, `short_name`, `generation`, `release_year`, `color`, `games_count`.
- `logo`: `{md, md_png}`، و `image`.
- `supported_formats`, `compatibility_notes`, `install_notes`.

### `GET /platforms/{slug}`
جهاز واحد، مثل `ps2`. يعيد 404 إن لم يوجد.

### `GET /categories`
التصنيفات الاثنا عشر مع `games_count`.

### `GET /games`
قائمة الألعاب المنشورة، وكل معاملات البحث والتصفية اختيارية:

| المعامل | مثال | الوصف |
|---|---|---|
| `q` | `moon` | بحث في الاسم والمطور والناشر ورمز المنتج، ويدعم العربية |
| `platform` | `ps4` | الجهاز |
| `category` | `racing` | التصنيف |
| `year` / `year_from` / `year_to` | `2004` | سنة الإصدار |
| `company` | `Iron` | يبدأ بها اسم المطور أو الناشر |
| `language` | `ar` | لغة مدعومة |
| `region` | `EUR` | المنطقة: `EUR`, `USA`, `JPN`, `ASIA`, `MENA`, `GLOBAL` |
| `size_min` / `size_max` | `50` | الحجم بالـ GB |
| `rating_min` | `9` | أقل تقييم |
| `added_within` | `7` | أضيفت خلال آخر N يوم |
| `availability` | `available` | توفر الملفات: `available`, `unavailable`, `coming_soon` |
| `featured` | `1` | المميزة فقط |
| `updated_since` | `2026-11-01T00:00:00Z` | ما تغيّر بعد هذا الوقت، للمزامنة في التطبيقات |
| `sort` | `rating` | الترتيب: `newest` (الافتراضي)، `rating`, `downloads`, `title`, `year`, `updated`, `relevance` (الافتراضي مع `q`) |
| `order` | `asc` | اتجاه الترتيب: `asc` أو `desc` |

كل عنصر في القائمة يحتوي على:
- `id`, `title`, `slug`, `product_code`.
- `platform`: `{id, slug, name, short_name, color}`.
- `release_year`, `developer`, `publisher`, `age_rating`.
- `size_bytes`, `size_human`, `languages[]`, `region`, `version`, `edition`.
- `rating`, `files_status`, `is_featured`.
- `is_favorite`: يكون `true` عند إرسال رمز مستخدم أضاف اللعبة لمفضلته.
- `downloads`, `views`, `favorites`.
- `categories[]`.
- `cover`: `{sm, sm_jpg, md, md_jpg}`.
- `url`, `created_at`, `updated_at`.

### `GET /games/{id أو slug}`
تفاصيل اللعبة. تُسجَّل مشاهدة في سجل المستخدم إن أُرسل رمزه. تضيف إلى حقول القائمة:
- `description`, `banner`, `trailer`.
- `screenshots[]`, `versions[]`, `links[]`, `compatibility[]`.
- `platform_info`: الصيغ المدعومة، وملاحظات التوافق، وطرق التثبيت الرسمية.
- `files[]`. كل ملف يحتوي على:
  - `id`, `type`, `label`, `name`, `size_bytes`, `sha256`, `version`, `region`, `access_level`.
  - `can_download` و `deny_reason`: تُحسبان للمستخدم الحالي.

المسار الداخلي للملف على القرص لا يُعرض أبداً.

### `GET /games/{id}/related`
حتى 8 ألعاب مشابهة من الجهاز نفسه والتصنيفات نفسها.

### `GET /search?q=…`
مثل `GET /games` مع معامل `q` إلزامي. مع `suggest=1` تعود قائمة مختصرة للبحث الفوري (حتى 8 عناصر):

```json
{ "id": 10, "title": "Moonwell Chronicles", "slug": "moonwell-chronicles-ps4", "platform": "PS4", "year": 2020,
  "cover": "https://…-sm.webp", "cover_jpg": "https://…-sm.jpg", "url": "https://…/game/moonwell-chronicles-ps4" }
```

### `GET /updates`
آخر التحديثات المنشورة. فلتر اختياري `type` يقبل:
- `game_added`
- `game_updated`
- `patch`
- `announcement`
- `maintenance`

---

## 2. الحساب والمصادقة

| الطلب | الجسم (JSON) | النتيجة |
|---|---|---|
| `POST /auth/register` | `username, email, password, password_confirmation, device_name?` | `201` مع `token` و `user`. تُرسل رسالة تأكيد البريد |
| `POST /auth/login` | `login` (اسم المستخدم أو البريد)، `password`, `device_name?` | `token`, `token_type`, `expires_at`, `user` |
| `POST /auth/logout` | — | يُلغى الرمز الحالي |
| `POST /auth/forgot-password` | `email` | الرد نفسه دائماً، سواء وُجد البريد أم لا |
| `POST /auth/reset-password` | `token, password, password_confirmation` | الرمز صالح لاستخدام واحد، وتُلغى بعده كل الجلسات |
| `POST /auth/resend-verification` | — (يحتاج رمزاً) | إعادة إرسال رسالة التأكيد |

**حدود المحاولات لكل عنوان IP:**

| المسار | الحد |
|---|---|
| الدخول | 15 محاولة كل 5 دقائق |
| التسجيل | 6 محاولات كل 10 دقائق |
| الاستعادة | 5 محاولات كل 15 دقيقة |

إضافة إلى ذلك يُقفل الحساب بعد 5 محاولات خاطئة لمدة 15 دقيقة، ويعيد الخادم `429 account_locked`.

**كلمة المرور:** 8 أحرف على الأقل، وتجمع بين الحروف والأرقام.

## 3. مسارات المستخدم (تتطلب رمزاً)

| الطلب | الوصف |
|---|---|
| `GET /user/profile` | بيانات الحساب، والدور، و `permissions[]`، و `stats` (المفضلة، والتنزيلات، والمشاهدات)، و `unread_notifications` |
| `PUT /user/profile` | `display_name`, `bio`, `email`. تغيير البريد يتطلب تأكيده من جديد |
| `POST /user/avatar` | `multipart/form-data`، الحقل `file` (صورة) |
| `PUT /user/password` | `current_password, password, password_confirmation`. يُلغي جلسات الأجهزة الأخرى ويُبقي الجهاز الحالي |
| `PUT /user/preferences` | `theme` (`dark`، `light`، `auto`)، `music` (true/false)، `volume` (0–100)، `sfx` |
| `GET /user/favorites` | الألعاب المفضلة |
| `POST /user/favorites` | `{ "game_id": 10 }` ويعيد `201` |
| `DELETE /user/favorites/{gameId}` | إزالة من المفضلة |
| `GET /user/history` | سجل المشاهدة، مع `viewed_at` لكل عنصر |
| `DELETE /user/history` | مسح سجل المشاهدة |
| `GET /user/downloads` | سجل التنزيلات |
| `GET /user/updates` | تحديثات الألعاب التي في المفضلة |
| `GET /user/notifications` | الإشعارات، ويحتوي `meta.unread` على عدد غير المقروء |
| `POST /user/notifications/read` | `{ "id": 5 }` لإشعار واحد، أو جسم فارغ لكل الإشعارات |
| `GET /user/tokens` | الأجهزة المتصلة (الرموز) |
| `DELETE /user/tokens/{id}` | فصل جهاز |

## 4. التنزيل المصرح به

**الخطوة 1:** اطلب رابطاً موقّعاً. يُسمح بالطلب بدون رمز إن كان الملف «عاماً»:

```
POST /games/{gameId}/files/{fileId}/link
```

```json
{ "url": "https://…/dl/6/demo-data-pack.bin?u=1&e=1795646694&s=8700fd…", "expires_at": "2026-11-25T22:44:54+00:00",
  "file_id": 6, "name": "demo-data-pack.bin", "size": 25165824, "sha256": "7708d521…" }
```

الأسباب التي تمنع إصدار الرابط:

| الحالة | الرد |
|---|---|
| الملف يتطلب تسجيل الدخول ولم يُرسل رمز | `401` |
| الصلاحية غير كافية (بريد غير مؤكد، أو ملف للمشرفين أو للمدراء فقط، أو حساب ممنوع من التنزيل) | `403` |
| تجاوز عدد الروابط في الساعة (60 افتراضياً) | `429` |

**الخطوة 2:** نزّل من `url` بطلب GET عادي. لا يحتاج هذا الطلب رمزاً، فالتوقيع يحدد المستخدم.
- يدعم الخادم `Range: bytes=start-end` ويرد بـ `206` ومعه `Content-Range`. هكذا يُستأنف التنزيل من حيث توقف.
- يدعم `If-Range` و `ETag`.
- الرابط صالح 6 ساعات افتراضياً. إن انتهى أثناء تنزيل طويل (`403`)، اطلب رابطاً جديداً وأكمل من نفس الموضع.
- الصلاحية تُفحص من جديد في كل طلب، فسحب الصلاحية يوقف حتى الروابط الصادرة سابقاً.
- أي تعديل في الرابط (رقم الملف، أو المستخدم، أو وقت الانتهاء) يجعله مرفوضاً.

**الخطوة 3:** احسب SHA-256 لما نزّلته وقارنه بـ `sha256`.

## 5. مسارات الإدارة (تتطلب رمز حساب له صلاحية `admin.access` + صلاحية كل عملية)

### الألعاب

| الطلب | الصلاحية | ملاحظات |
|---|---|---|
| `GET /admin/games` | `admin.access` | يشمل المخفية. فلتر إضافي `published=0/1` |
| `POST /admin/games` | `games.create` | انظر الحقول أدناه. يرد `201`، أو `409 duplicate_game` مع `duplicates[]` |
| `GET /admin/games/{id}` | `admin.access` | كل التفاصيل مع الملفات |
| `PUT /admin/games/{id}` | `games.edit` | تعديل جزئي: أرسل الحقول المراد تغييرها فقط |
| `DELETE /admin/games/{id}` | `games.delete` | يحذف السجل والصور والملفات من القرص |
| `POST /admin/games/{id}/publish` | `games.publish` | `{ "published": true }` |
| `GET /admin/games/check-duplicates?title=&platform_id=` | `games.create` | فحص فوري أثناء الكتابة |
| `GET /admin/duplicates` | `games.edit` | مجموعات الألعاب المكررة |
| `POST /admin/import` | `games.import` | `multipart` بالحقلين `file` (csv/json) و `mode=preview`، ثم `{token, mode:"commit", on_duplicate:"skip|update|create"}` |

**حقول اللعبة:**

| الحقل | ملاحظات |
|---|---|
| `platform_id`* | إلزامي |
| `title`* | إلزامي |
| `categories` | معرّفات أو slugs |
| `description` | |
| `release_year` | |
| `developer`, `publisher` | |
| `age_rating` | |
| `size` | مثل `"48.5 GB"`، أو `size_bytes` بالبايت |
| `languages` | مصفوفة أو `"ar\|en"` |
| `region` | |
| `version`, `edition` | |
| `rating` | من 0 إلى 10 |
| `product_code` | |
| `trailer_url` | YouTube أو Vimeo أو mp4 |
| `files_status` | |
| `compatibility_notes` | |
| `is_published`, `is_featured` | |
| `allow_duplicate` | لتجاوز رفض التكرار |

### صور اللعبة ومحتوياتها

| الطلب | ملاحظات |
|---|---|
| `POST /admin/games/{id}/cover` و `/banner` | `multipart` بالحقل `file` (JPEG أو PNG أو WebP أو GIF، وملفات SVG مرفوضة)، **أو** JSON `{"url":"https://…"}` لصورة من الإنترنت تُحفظ كما هي ولا يُنزّلها الخادم (https فقط، حتى 255 حرفاً). يعيد روابط كل المقاسات |
| `POST /admin/games/{id}/screenshots` | `file` و `caption?`، أو `{"url":"https://…","caption":"…"}`. وللحذف: `DELETE …/screenshots/{imageId}` |
| `GET/POST /admin/games/{id}/versions` | للتعديل `PUT …/versions/{vid}` وللحذف `DELETE` بنفس المسار. الحقول: `version`, `region`, `release_date`, `size`, `changelog`, `is_latest`, `notify` (إشعار من أضافها للمفضلة) |
| `GET/POST /admin/games/{id}/links` | للتعديل والحذف `PUT/DELETE …/links/{lid}`. الحقول: `title`, `url` (http/https فقط)، `link_type` (`official_store`، `publisher`، `authorized_mirror`، `info`)، `region`, `sort_order`, `is_active` |
| `POST /admin/games/{id}/compat` | يُنشئ أو يُحدّث حسب `target`. الحقول: `target`, `status` (`perfect`، `playable`، `ingame`، `menus`، `boots`، `nothing`، `unknown`)، `notes`, `tested_at`. وللحذف: `DELETE …/compat/{cid}` |

### الملفات

| الطلب | ملاحظات |
|---|---|
| `GET /admin/games/{id}/files` | ملفات اللعبة |
| `PUT /admin/files/{fileId}` | `label`, `file_type`, `access_level` (`public`، `registered`، `verified`، `moderator`، `admin`)، `region`, `version_id`, `is_active` |
| `DELETE /admin/files/{fileId}` | يحذف الملف من القرص أيضاً |
| `POST /admin/files/{fileId}/verify` | يعيد حساب SHA-256 من القرص ويقارنه بالمسجّل |

### الكتالوج والمستخدمون والمحتوى

| الطلب | الوصف |
|---|---|
| `GET/POST /admin/platforms` | الأجهزة. التعديل `PUT /admin/platforms/{id}`، والصور `POST …/{id}/logo` و `…/{id}/image` |
| `POST /admin/categories` | التصنيفات. التعديل والحذف `PUT/DELETE /admin/categories/{id}` |
| `GET /admin/users` | المستخدمون، مع فلاتر `q` و `role` (slug) و `status` و `verified` |
| `GET/PUT/DELETE /admin/users/{id}` | الحقول: `role_id`, `status`, `can_download`, `unlock`, `email_verified`, `display_name` |
| `GET/POST /admin/updates` | التحديثات. التعديل والحذف `PUT/DELETE /admin/updates/{id}`. الحقول: `type`, `title`, `body`, `game_id`, `platform_id`, `version_id`, `is_published`, `notify_favorites` |
| `GET/POST /admin/notifications` | الإشعارات. الحقول: `title`, `body`, `link` (يبدأ بـ `/` أو `https://`)، `audience` (`all` أو `user`)، `user` (المعرّف أو اسم المستخدم أو البريد) |
| `GET /admin/stats` | إحصاءات لوحة المعلومات |
| `GET /admin/health` | حالة الخادم |
| `GET /admin/downloads` | سجل التنزيلات |
| `GET /admin/storage` | التخزين |
| `GET /admin/logs` | سجل النشاط، مع فلاتر `q` و `action` و `user_id` و `from` |

### بقية لوحة الإدارة (يستخدمها تطبيق الإدارة على Android)

كل طلب يعيد التحقق من الصلاحية المذكورة، والرد `403` إن لم تكن لدى الحساب.

| الطلب | الصلاحية | الوصف |
|---|---|---|
| `POST /admin/games/bulk` | `games.publish` (و`games.delete` للحذف) | `{"ids":[…], "action":"publish|hide|feature|unfeature|delete"}`. الرد: `done` وقائمة `missing` للمعرفات غير الموجودة |
| `POST /admin/games/{id}/screenshots/reorder` | `media.upload` | `{"ids":[…]}` بالترتيب الجديد |
| `DELETE /admin/games/{id}/trailer` | `games.edit` | يزيل الفيديو التعريفي (ويحذف الملف إن كان مرفوعاً) |
| `DELETE /admin/notifications/{id}` | `notifications.send` | حذف إشعار مرسل |
| `POST /admin/users` | `users.manage` (و`roles.assign` لغير دور المستخدم) | `{username, email, password, role_id}`؛ لا يمكن إنشاء دور مساوٍ لدورك أو أعلى |
| `GET /admin/roles` | `roles.assign` | الأدوار والصلاحيات و`matrix` بصيغة `{"roleId":[permIds]}` و`can_edit` |
| `PUT /admin/roles` | المدير الرئيسي فقط | `{"matrix":{"2":[1,2,3]}}`؛ دور المدير الرئيسي يحتفظ بكل الصلاحيات دائماً |
| `GET /admin/tokens` | `api.manage` | رموز الدخول النشطة لكل الحسابات، مع `is_current` |
| `DELETE /admin/tokens/{id}` | `api.manage` | إلغاء رمز |
| `GET /admin/security` | `security.manage` | حالة HTTPS، الحسابات المقفلة، آخر محاولات الدخول الفاشلة، وحالة الخادم |
| `GET /admin/settings` | إحدى صلاحيات المجموعات | المجموعات المسموحة فقط، ولكل حقل `key` و`type` (`bool`، `int` مع `min`/`max`، `enum` مع `options`، `string` مع `max_length`، `url`، `email`، `secret`). الأسرار لا تُعاد أبداً؛ يُعاد `is_set` فقط |
| `PUT /admin/settings/{group}` | `settings.manage` (general)، `security.manage` (security)، `api.manage` (api)، `backups.manage` (backups) | تحديث جزئي: تُحفظ المفاتيح المرسلة فقط، بصيغة `site.name` أو `site_name` |
| `POST /admin/settings/mail-test` | `settings.manage` | يرسل رسالة اختبار إلى بريد الحساب |
| `DELETE /admin/settings/music` | `settings.manage` | يزيل ملف الموسيقى الخلفية (الرفع عبر الرفع المجزأ بغرض `music`) |
| `GET /admin/files` | `files.manage` | كل الملفات المستضافة، مع `q` (الاسم أو اللعبة أو SHA-256) و`type` و`game_id` |
| `GET /admin/storage/orphans` | `storage.manage` | ملفات على الأقراص غير مسجلة في المكتبة |
| `POST /admin/storage/{cleanup|orphans|cache}` | `storage.manage` | تنظيف الرفع المتوقف، حذف الملفات اليتيمة، مسح الكاش |
| `POST /admin/queue/run` | `files.upload` أو `settings.manage` | ينفذ المهام المنتظرة (حتى 25 مهمة أو 50 ثانية) |
| `GET /admin/logs/errors` | `logs.view` | آخر الأخطاء من سجل الخادم، مع `level` (`error`، `warning`، `info`) |
| `GET /admin/backups` | `backups.manage` | النسخ الاحتياطية، و`meta` فيها `frequency` و`keep` و`last_run` و`can_restore` |
| `POST /admin/backups` | `backups.manage` | `{"include_media": true|false}` |
| `GET /admin/backups/{name}/download` | `backups.manage` | تنزيل مباشر يدعم Range |
| `POST /admin/backups/{name}/restore` | `backups.restore` | `{"password": "…", "with_media": true}`؛ كلمة مرور الحساب مطلوبة، ويُنشئ الخادم نسخة أمان قبل الاستعادة |
| `DELETE /admin/backups/{name}` | `backups.manage` | حذف نسخة |

## 6. بروتوكول الرفع المجزأ

يعتمد عليه مدير الرفع في الموقع وتطبيق الإدارة على Android.

**1. البدء:**

```
POST /admin/uploads/init
{ "purpose": "game_file", "game_id": 12, "filename": "game.pkg", "size": 52428800000,
  "file_type": "game", "access_level": "registered", "region": "EUR", "label": "النسخة الكاملة", "sha256": "اختياري" }
```

**قيم `purpose`:**
- `game_file`
- `cover` و `banner` و `screenshot` و `trailer`
- `avatar`
- `platform_logo` و `platform_image`
- `music`
- `backup` و `import`

**الرد:**

```json
{ "upload_id": "5fb79…(32 hex)", "chunk_size": 5242880, "total_chunks": 9766, "received": [], "status": "uploading", "resumed": false }
```

**الاستكمال:** إرسال `init` مرة ثانية بنفس الاسم والحجم والغرض، ومن نفس الحساب، يعيد الجلسة نفسها مع `"resumed": true`، و `received` تحتوي على أرقام الأجزاء الموجودة. أرسل الأجزاء الناقصة فقط.

**2. الأجزاء** (بأي ترتيب، ويمكن بالتوازي):

```
POST /admin/uploads/chunk
X-Upload-Id: 5fb79…
X-Chunk-Index: 0
X-Chunk-Sha256: <sha256 للجزء>      (اختياري لكن مستحسن)
Content-Type: application/octet-stream
<البايتات الخام>
```

صيغ بديلة لنفس الطلب:
- `multipart/form-data` بالحقول `upload_id` و `chunk_index` و `chunk_sha256` و `chunk` (ملف).
- JSON بالشكل `{upload_id, chunk_index, data_base64}`. هذه الصيغة مفيدة لأدوات مثل Sketchware.

| الحالة | النتيجة |
|---|---|
| تكرار إرسال جزء | آمن ولا يُحتسب مرتين |
| بصمة الجزء خاطئة | `422 chunk_checksum_mismatch` |
| حجم الجزء خاطئ | `422` |

**3. الإنهاء:**

```
POST /admin/uploads/complete   { "upload_id": "…", "sha256": "<بصمة الملف كاملاً، اختياري>" }
```

| الحالة | الرد |
|---|---|
| أجزاء ناقصة | `409` |
| البصمة النهائية غير مطابقة | `422` ويُرفض الملف |
| نجاح | `200` مع `status: completed` و `result`، مثل `file_id` أو روابط الصورة |
| ملف كبير | `202` مع `status: processing`. تابع الحالة عبر `GET /admin/uploads/{id}` حتى تصبح `completed` |

**4. المتابعة والإلغاء:**
- `GET /admin/uploads/{id}`: الحالة والأجزاء المستلمة.
- `GET /admin/uploads`: القائمة.
- `DELETE /admin/uploads/{id}`: إلغاء.

**قيود الرفع:**
- الامتدادات من القائمة البيضاء فقط، والامتدادات التنفيذية مرفوضة دائماً.
- الحجم الأقصى 200 GB افتراضياً. ما يتجاوزه يُرفض بـ `413`.

## 7. أمثلة سريعة (curl)

```bash
BASE=https://library.example.com/api/v1
TOKEN=$(curl -s -X POST $BASE/auth/login -H 'Content-Type: application/json' \
  -d '{"login":"admin","password":"…","device_name":"terminal"}' | jq -r .data.token)

curl -s "$BASE/games?platform=ps4&sort=rating&per_page=5" | jq '.data[].title'
curl -s -X POST $BASE/user/favorites -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"game_id":10}'
URL=$(curl -s -X POST $BASE/games/10/files/6/link -H "Authorization: Bearer $TOKEN" | jq -r .data.url)
curl -C - -o demo-data-pack.bin "$URL"     # -C - يستكمل تنزيلاً متوقفاً
```

## 8. ملاحظة للتطبيقات

- خزّن الرمز في مكان آمن على الجهاز، وأرسل `device_name` واضحاً، مثل `"Android — Pixel 8"`. هكذا يرى المستخدم أجهزته ويفصلها من حسابه.
- الرد `401` يعني أن الرمز انتهى أو أُلغي، فأعد تسجيل الدخول.
- استخدم `updated_since` لمزامنة ما تغيّر فقط، و `ETag` لتوفير البيانات.
