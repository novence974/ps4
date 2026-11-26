<?php
declare(strict_types=1);
/**
 * Idempotent data upgrades for installs that already exist (run by deploy/alwaysdata/update.sh, safe to repeat).
 * Each change only applies while the row still holds the old default text, so your own edits are never overwritten.
 *   php bin/upgrade.php
 */
require __DIR__ . '/_cli.php';

use App\Core\Cache;
use App\Core\DB;

const TEXT_CHANGES = [
    ['platforms', 'slug', 'ps5', 'supported_formats', 'أقراص Ultra HD Blu-ray، تنزيلات PlayStation Store الرسمية', 'أقراص Ultra HD Blu-ray، تنزيلات PlayStation Store'],
    ['platforms', 'slug', 'ps4', 'supported_formats', 'أقراص Blu-ray، حزم PKG الرسمية الموقّعة من المتجر', 'أقراص Blu-ray، حزم PKG'],
    ['platforms', 'slug', 'ps3', 'supported_formats', 'أقراص Blu-ray، تنزيلات PSN الرسمية (حيث ما زالت متاحة)', 'أقراص Blu-ray، تنزيلات PSN'],
    ['platforms', 'slug', 'ps5', 'compatibility_notes', 'يشغّل معظم ألعاب PS4 عبر التوافق الرجعي الرسمي. لا يشغّل أقراص PS3 أو PS2 أو PS1 مباشرة؛ بعض الكلاسيكيات متاحة رسمياً عبر المتجر.', 'يشغّل معظم ألعاب PS4 عبر التوافق الرجعي. لا يشغّل أقراص PS3 أو PS2 أو PS1 مباشرة؛ بعض الكلاسيكيات متاحة عبر المتجر.'],
    ['platforms', 'slug', 'ps4', 'compatibility_notes', 'لا يشغّل أقراص PS3/PS2/PS1. بعض ألعاب PS2 متاحة رسمياً كإصدارات PS4. أغلب ألعاب PS4 تعمل على PS5.', 'لا يشغّل أقراص PS3/PS2/PS1. بعض ألعاب PS2 متاحة كإصدارات PS4. أغلب ألعاب PS4 تعمل على PS5.'],
    ['platforms', 'slug', 'ps3', 'compatibility_notes', 'الطرازات الأولى تدعم ألعاب PS2 عتادياً، وجميع الطرازات تدعم أقراص PS1. المحاكي المفتوح RPCS3 يتطلب البرنامج الثابت الرسمي وألعاباً تمتلكها.', 'الطرازات الأولى تدعم ألعاب PS2 عتادياً، وجميع الطرازات تدعم أقراص PS1. المحاكي المفتوح RPCS3 يتطلب البرنامج الثابت وألعاباً تمتلكها.'],
    ['platforms', 'slug', 'ps5', 'install_notes', 'التثبيت يتم من متجر PlayStation الرسمي أو من القرص الأصلي. تعرض هذه المكتبة البيانات ومعلومات التوافق وروابط المصادر المصرح بها فقط.', ''],
    ['platforms', 'slug', 'ps4', 'install_notes', 'الحزم الرسمية تُثبّت من المتجر أو القرص. تثبيت حزم غير موقّعة غير مدعوم في الأجهزة القياسية ولا تقدمه هذه المنظومة؛ راجع قسم التوافق في التوثيق.', ''],
    ['platforms', 'slug', 'ps3', 'install_notes', 'التشغيل من القرص الأصلي أو من مشترياتك في PSN.', ''],
    ['platforms', 'slug', 'ps2', 'install_notes', 'التشغيل من القرص الأصلي، أو من نسخة شخصية لقرص تمتلكه على محاكي متوافق حيث يسمح القانون المحلي.', ''],
    ['platforms', 'slug', 'ps1', 'install_notes', 'التشغيل من القرص الأصلي أو من إصدارات الكلاسيكيات الرسمية.', ''],
    ['roles', 'slug', 'user', 'description', 'تصفح وتنزيل الملفات المصرح بها والمفضلة', 'تصفح وتنزيل الملفات والمفضلة'],
    ['permissions', 'slug', 'files.download', 'name', 'تنزيل الملفات المصرح بها', 'تنزيل الملفات'],
    ['permissions', 'slug', 'links.manage', 'name', 'إدارة روابط التنزيل الرسمية', 'إدارة روابط التنزيل'],
];

$n = 0;
foreach (TEXT_CHANGES as [$table, $key, $value, $column, $old, $new]) {
    $n += DB::run("UPDATE `$table` SET `$column` = ? WHERE `$key` = ? AND `$column` = ?", [$new, $value, $old])->rowCount();
}
try {
    Cache::bump('perms');
    Cache::bump('games');
} catch (\Throwable) {
}
echo "Upgrade: {$n} rows updated.\n";
