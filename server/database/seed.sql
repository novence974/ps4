-- =====================================================================
--  ULTIMATE PS LIBRARY — Base data (roles, permissions, platforms, categories)
--  Safe to re-run: uses INSERT IGNORE / ON DUPLICATE KEY UPDATE.
--  The first Super Admin account is created by the installer (no default password).
-- =====================================================================
SET NAMES utf8mb4;

INSERT INTO roles (id, slug, name, level, description) VALUES
 (1, 'super_admin', 'المدير الرئيسي', 100, 'صلاحيات كاملة بما فيها الاستعادة وتعيين المدراء'),
 (2, 'admin',       'المدير',          80, 'إدارة المكتبة والمستخدمين والإعدادات'),
 (3, 'moderator',   'مشرف المكتبة',    50, 'إضافة وتعديل ونشر الألعاب ورفع الملفات'),
 (4, 'user',        'مستخدم',          10, 'تصفح وتنزيل الملفات المصرح بها والمفضلة')
ON DUPLICATE KEY UPDATE name = VALUES(name), level = VALUES(level), description = VALUES(description);

INSERT INTO permissions (slug, name, group_name) VALUES
 ('admin.access',        'دخول لوحة التحكم',               'general'),
 ('games.create',        'إضافة الألعاب',                   'games'),
 ('games.edit',          'تعديل الألعاب',                   'games'),
 ('games.delete',        'حذف الألعاب',                     'games'),
 ('games.publish',       'نشر وإخفاء الألعاب',              'games'),
 ('games.import',        'الاستيراد بالجملة CSV/JSON',      'games'),
 ('media.upload',        'رفع الأغلفة والصور والفيديو',     'games'),
 ('files.upload',        'رفع ملفات الألعاب',               'files'),
 ('files.manage',        'إدارة وحذف ملفات الألعاب',        'files'),
 ('files.download',      'تنزيل الملفات المصرح بها',        'files'),
 ('links.manage',        'إدارة روابط التنزيل الرسمية',     'files'),
 ('platforms.manage',    'إدارة الأجهزة',                   'catalog'),
 ('categories.manage',   'إدارة التصنيفات',                 'catalog'),
 ('updates.manage',      'إدارة التحديثات',                 'catalog'),
 ('notifications.send',  'إرسال الإشعارات',                 'users'),
 ('users.view',          'عرض المستخدمين',                  'users'),
 ('users.manage',        'إدارة المستخدمين',                'users'),
 ('roles.assign',        'تعيين الأدوار',                   'users'),
 ('downloads.view',      'عرض سجلات التنزيل',               'system'),
 ('storage.manage',      'إدارة التخزين',                   'system'),
 ('api.manage',          'إعدادات API والرموز',             'system'),
 ('security.manage',     'إعدادات الأمان',                  'system'),
 ('logs.view',           'سجلات النشاط والأخطاء',           'system'),
 ('backups.manage',      'إنشاء النسخ الاحتياطية',          'system'),
 ('backups.restore',     'استعادة النسخ الاحتياطية',        'system'),
 ('settings.manage',     'الإعدادات العامة',                'system')
ON DUPLICATE KEY UPDATE name = VALUES(name), group_name = VALUES(group_name);

-- Super Admin: every permission (also enforced in code)
INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 1, id FROM permissions;
-- Admin: everything except restoring backups
INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 2, id FROM permissions WHERE slug <> 'backups.restore';
-- Moderator: library work
INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 3, id FROM permissions WHERE slug IN
 ('admin.access','games.create','games.edit','games.publish','media.upload','files.upload','files.download','links.manage','updates.manage');
-- User
INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 4, id FROM permissions WHERE slug IN ('files.download');

INSERT INTO platforms (slug, name, short_name, release_year, generation, accent_color, sort_order, description, supported_formats, compatibility_notes, install_notes) VALUES
 ('ps5', 'PlayStation 5', 'PS5', 2020, 9, '#60a5fa', 1,
  'الجيل التاسع: أقراص SSD فائقة السرعة، تتبع الأشعة، صوت ثلاثي الأبعاد، ويد تحكم بردود فعل لمسية.',
  'أقراص Ultra HD Blu-ray، تنزيلات PlayStation Store الرسمية',
  'يشغّل معظم ألعاب PS4 عبر التوافق الرجعي الرسمي. لا يشغّل أقراص PS3 أو PS2 أو PS1 مباشرة؛ بعض الكلاسيكيات متاحة رسمياً عبر المتجر.',
  'التثبيت يتم من متجر PlayStation الرسمي أو من القرص الأصلي. تعرض هذه المكتبة البيانات ومعلومات التوافق وروابط المصادر المصرح بها فقط.'),
 ('ps4', 'PlayStation 4', 'PS4', 2013, 8, '#6366f1', 2,
  'الجيل الثامن: أكبر مكتبة ألعاب حديثة، ميزات المشاركة والبث، ويد التحكم DualShock 4.',
  'أقراص Blu-ray، حزم PKG الرسمية الموقّعة من المتجر',
  'لا يشغّل أقراص PS3/PS2/PS1. بعض ألعاب PS2 متاحة رسمياً كإصدارات PS4. أغلب ألعاب PS4 تعمل على PS5.',
  'الحزم الرسمية تُثبّت من المتجر أو القرص. تثبيت حزم غير موقّعة غير مدعوم في الأجهزة القياسية ولا تقدمه هذه المنظومة؛ راجع قسم التوافق في التوثيق.'),
 ('ps3', 'PlayStation 3', 'PS3', 2006, 7, '#a855f7', 3,
  'الجيل السابع: معالج Cell، مشغل Blu-ray مدمج، وبداية شبكة PlayStation Network.',
  'أقراص Blu-ray، تنزيلات PSN الرسمية (حيث ما زالت متاحة)',
  'الطرازات الأولى تدعم ألعاب PS2 عتادياً، وجميع الطرازات تدعم أقراص PS1. المحاكي المفتوح RPCS3 يتطلب البرنامج الثابت الرسمي وألعاباً تمتلكها.',
  'التشغيل من القرص الأصلي أو من مشترياتك في PSN.'),
 ('ps2', 'PlayStation 2', 'PS2', 2000, 6, '#22d3ee', 4,
  'الجيل السادس: الجهاز المنزلي الأكثر مبيعاً في التاريخ، مع مشغل DVD ومكتبة أسطورية.',
  'أقراص DVD و CD (النسخ الشخصية الشائعة: ISO، CHD)',
  'متوافق مع ألعاب PS1. المحاكي المفتوح PCSX2 يتطلب BIOS مستخرجاً من جهازك الشخصي.',
  'التشغيل من القرص الأصلي، أو من نسخة شخصية لقرص تمتلكه على محاكي متوافق حيث يسمح القانون المحلي.'),
 ('ps1', 'PlayStation', 'PS1', 1994, 5, '#f472b6', 5,
  'الجيل الخامس: الجهاز الذي أطلق عصر الألعاب ثلاثية الأبعاد على الأقراص المدمجة.',
  'أقراص CD-ROM (النسخ الشخصية الشائعة: BIN/CUE، CHD، PBP)',
  'متوافق مع PS2 وأغلب طرازات PS3. محاكيات مثل DuckStation تتطلب BIOS من جهازك.',
  'التشغيل من القرص الأصلي أو من إصدارات الكلاسيكيات الرسمية.')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO categories (slug, name, name_en, icon, color, sort_order) VALUES
 ('action',     'الأكشن',            'Action',      'bolt',     '#8b5cf6', 1),
 ('adventure',  'المغامرات',         'Adventure',   'compass',  '#6366f1', 2),
 ('open-world', 'العالم المفتوح',    'Open World',  'globe',    '#3b82f6', 3),
 ('horror',     'الرعب',             'Horror',      'ghost',    '#a855f7', 4),
 ('racing',     'السباقات',          'Racing',      'flag',     '#22d3ee', 5),
 ('football',   'كرة القدم',         'Football',    'ball',     '#10b981', 6),
 ('sports',     'الرياضة',           'Sports',      'trophy',   '#14b8a6', 7),
 ('fighting',   'القتال',            'Fighting',    'fist',     '#f43f5e', 8),
 ('family',     'الألعاب العائلية',  'Family',      'home',     '#f59e0b', 9),
 ('kids',       'ألعاب الأطفال',     'Kids',        'star',     '#fb7185', 10),
 ('rpg',        'RPG',               'RPG',         'sword',    '#c084fc', 11),
 ('strategy',   'ألعاب الاستراتيجية','Strategy',    'chess',    '#38bdf8', 12)
ON DUPLICATE KEY UPDATE name = VALUES(name);
