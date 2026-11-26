<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;

/**
 * Public media (covers, screenshots, avatars, platform art, trailers, music).
 * Images are always decoded and re-encoded with GD (strips metadata and any embedded payload),
 * resized to fixed variants and saved as WebP + a JPEG/PNG fallback for clients without WebP (PS4 app).
 */
final class MediaService
{
    public const SPECS = [
        'cover' => ['dir' => 'covers', 'variants' => ['sm' => [300, 450, false], 'md' => [600, 900, false]], 'fallback' => 'jpg'],
        'banner' => ['dir' => 'banners', 'variants' => ['sm' => [960, 540, false], 'lg' => [1920, 1080, false]], 'fallback' => 'jpg'],
        'screenshot' => ['dir' => 'screens', 'variants' => ['sm' => [480, 270, false], 'lg' => [1600, 900, false]], 'fallback' => 'jpg'],
        'avatar' => ['dir' => 'avatars', 'variants' => ['sm' => [256, 256, true]], 'fallback' => 'jpg'],
        'platform_logo' => ['dir' => 'platforms', 'variants' => ['md' => [512, 512, false]], 'fallback' => 'png'],
        'platform_image' => ['dir' => 'platforms', 'variants' => ['sm' => [800, 450, false], 'lg' => [1600, 900, false]], 'fallback' => 'jpg'],
    ];

    public static function root(): string
    {
        return PUBLIC_PATH . '/media';
    }

    /** @return array{path:string,width:int,height:int} */
    public static function storeImage(string $src, string $purpose): array
    {
        $spec = self::SPECS[$purpose] ?? throw new \InvalidArgumentException('Unknown media purpose ' . $purpose);
        $max = max(1, (int) Settings::get('uploads.image_max_mb', 15)) * 1024 * 1024;
        if (!is_file($src) || filesize($src) === 0) {
            throw ValidationException::single('file', 'لم يتم استلام الصورة');
        }
        if (filesize($src) > $max) {
            throw ValidationException::single('file', 'حجم الصورة أكبر من ' . human_bytes($max));
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($src) ?: '';
        $info = @getimagesize($src);
        $types = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
        if (!$info || !in_array($info[2], $types, true) || !str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            throw ValidationException::single('file', 'الملف ليس صورة صالحة. الصيغ المقبولة: JPG، PNG، WebP، GIF');
        }
        if ($info[0] < 16 || $info[1] < 16 || $info[0] * $info[1] > 50_000_000) {
            throw ValidationException::single('file', 'أبعاد الصورة غير مقبولة');
        }
        $img = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG => @imagecreatefrompng($src),
            IMAGETYPE_WEBP => @imagecreatefromwebp($src),
            IMAGETYPE_GIF => @imagecreatefromgif($src),
        };
        if (!$img) {
            throw ValidationException::single('file', 'تعذرت قراءة الصورة، قد تكون تالفة');
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($src);
            $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
            if ($angle) {
                $rot = imagerotate($img, $angle, 0);
                if ($rot) {
                    imagedestroy($img);
                    $img = $rot;
                }
            }
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        $base = $spec['dir'] . '/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(10));
        $abs = self::root() . '/' . $base;
        if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0775, true) && !is_dir(dirname($abs))) {
            throw new \RuntimeException('Cannot create media directory');
        }
        foreach ($spec['variants'] as $name => [$w, $h, $crop]) {
            $v = self::resize($img, $w, $h, $crop);
            imagewebp($v, "{$abs}-{$name}.webp", 82);
            if ($spec['fallback'] === 'png') {
                imagepng($v, "{$abs}-{$name}.png", 6);
            } else {
                $flat = imagecreatetruecolor(imagesx($v), imagesy($v));
                imagefill($flat, 0, 0, imagecolorallocate($flat, 11, 13, 32));
                imagecopy($flat, $v, 0, 0, 0, 0, imagesx($v), imagesy($v));
                imageinterlace($flat, true);
                imagejpeg($flat, "{$abs}-{$name}.jpg", 84);
                imagedestroy($flat);
            }
            imagedestroy($v);
        }
        $out = ['path' => $base, 'width' => imagesx($img), 'height' => imagesy($img)];
        imagedestroy($img);
        return $out;
    }

    private static function resize(\GdImage $src, int $maxW, int $maxH, bool $crop): \GdImage
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $sx = 0;
        $sy = 0;
        if ($crop) {
            $side = min($sw, $sh);
            $sx = intdiv($sw - $side, 2);
            $sy = intdiv($sh - $side, 2);
            $sw = $sh = $side;
            $scale = min(1, $maxW / $side);
        } else {
            $scale = min(1, $maxW / $sw, $maxH / $sh);
        }
        $dw = max(1, (int) round($sw * $scale));
        $dh = max(1, (int) round($sh * $scale));
        $dst = imagecreatetruecolor($dw, $dh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);
        return $dst;
    }

    public static function isSafeBase(?string $base): bool
    {
        return is_string($base) && (bool) preg_match('#^[a-z_]+/\d{4}/\d{2}/[a-f0-9]{20}$#', $base);
    }

    public static function deleteImage(?string $base): void
    {
        if (!self::isSafeBase($base)) {
            return;
        }
        foreach (['webp', 'jpg', 'png'] as $ext) {
            foreach (glob(self::root() . '/' . $base . '-*.' . $ext) ?: [] as $f) {
                @unlink($f);
            }
        }
    }

    /** Variant URLs for API consumers (absolute). */
    public static function urls(?string $base, string $purpose): ?array
    {
        if (!$base) {
            return null;
        }
        $spec = self::SPECS[$purpose];
        $out = [];
        if (preg_match('#^https?://#', $base)) {
            foreach (array_keys($spec['variants']) as $v) {
                $out[$v] = $out[$v . '_' . $spec['fallback']] = $base;
            }
            return $out;
        }
        foreach (array_keys($spec['variants']) as $v) {
            $out[$v] = media_url($base, $v, 'webp', true);
            $out[$v . '_' . $spec['fallback']] = media_url($base, $v, $spec['fallback'], true);
        }
        return $out;
    }

    /** Trailers (mp4/webm) and music (mp3/ogg) are stored as-is after MIME sniffing. */
    public static function storeFile(string $src, string $kind, string $originalName): string
    {
        $allowed = [
            'trailer' => ['mp4' => ['video/mp4', 'application/mp4'], 'webm' => ['video/webm']],
            'music' => ['mp3' => ['audio/mpeg', 'audio/mp3'], 'ogg' => ['audio/ogg', 'application/ogg', 'audio/x-vorbis+ogg', 'audio/opus']],
        ][$kind] ?? throw new \InvalidArgumentException('Unknown kind');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($src) ?: '';
        if (!isset($allowed[$ext]) || !in_array($mime, $allowed[$ext], true)) {
            throw ValidationException::single('file', 'صيغة الملف غير مقبولة (' . $mime . '). المسموح: ' . implode(', ', array_keys($allowed)));
        }
        $rel = ($kind === 'trailer' ? 'trailers' : 'audio') . '/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(10)) . '.' . $ext;
        $abs = self::root() . '/' . $rel;
        if (!is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0775, true);
        }
        if (!@rename($src, $abs)) {
            if (!@copy($src, $abs)) {
                throw new \RuntimeException('Cannot store media file');
            }
            @unlink($src);
        }
        @chmod($abs, 0644);
        return 'media/' . $rel;
    }

    public static function deleteFile(?string $rel): void
    {
        if ($rel && preg_match('#^media/(trailers|audio)/\d{4}/\d{2}/[a-f0-9]{20}\.(mp4|webm|mp3|ogg)$#', $rel)) {
            @unlink(PUBLIC_PATH . '/' . $rel);
        }
    }

    public static function directorySize(): int
    {
        $total = 0;
        if (!is_dir(self::root())) {
            return 0;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $total += $f->getSize();
            }
        }
        return $total;
    }
}
