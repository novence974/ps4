<?php
declare(strict_types=1);

namespace App\Core;

final class UploadedFile
{
    public function __construct(
        public readonly string $name,
        public readonly string $tmpPath,
        public readonly int $size,
        public readonly int $error,
        public readonly ?string $clientMime
    ) {
    }

    public static function fromArray(array $f): ?self
    {
        if (!isset($f['tmp_name']) || is_array($f['tmp_name']) || (int) ($f['error'] ?? 4) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return new self((string) $f['name'], (string) $f['tmp_name'], (int) $f['size'], (int) $f['error'], $f['type'] ?? null);
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_uploaded_file($this->tmpPath);
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
    }

    public function mime(): string
    {
        return (new \finfo(FILEINFO_MIME_TYPE))->file($this->tmpPath) ?: 'application/octet-stream';
    }

    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم الملف أكبر من الحد المسموح في إعدادات الخادم',
            UPLOAD_ERR_PARTIAL => 'تم رفع جزء من الملف فقط، حاول مجدداً',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'تعذر حفظ الملف المؤقت على الخادم',
            UPLOAD_ERR_EXTENSION => 'أوقفت إضافة في الخادم عملية الرفع',
            default => 'ملف غير صالح',
        };
    }
}
