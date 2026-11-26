<?php
declare(strict_types=1);

namespace App\Core;

final class Str
{
    /** URL slug; keeps Latin letters/digits, transliterates accents, falls back to a random id. */
    public static function slug(string $text, int $max = 200): string
    {
        $t = $text;
        if (function_exists('transliterator_transliterate')) {
            $t = (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $t);
        } else {
            $t = mb_strtolower($t);
        }
        $t = preg_replace('/[^a-z0-9]+/', '-', $t) ?? '';
        $t = trim(substr($t, 0, $max), '-');
        return $t !== '' ? $t : 'game-' . bin2hex(random_bytes(3));
    }

    /** Normalised title used to detect duplicates: lowercase letters & digits only. */
    public static function normalizeTitle(string $title): string
    {
        $t = mb_strtolower($title);
        if (class_exists(\Normalizer::class)) {
            $t = (string) \Normalizer::normalize($t, \Normalizer::FORM_KD);
            $t = preg_replace('/\p{Mn}+/u', '', $t) ?? $t;
        }
        $t = str_replace(['&', '+'], ['and', 'plus'], $t);
        $t = preg_replace('/[^\p{L}\p{N}]+/u', '', $t) ?? '';
        return mb_substr($t, 0, 190);
    }

    public static function limit(string $s, int $n, string $end = '…'): string
    {
        return mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n)) . $end : $s;
    }

    /** Parses "45.2 GB", "700MB", "1.5tb" or plain bytes into bytes. */
    public static function parseSize(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }
        if (!preg_match('/^\s*([\d.,]+)\s*([kmgt]?i?b?)\s*$/i', (string) $value, $m)) {
            return null;
        }
        $n = (float) str_replace(',', '.', $m[1]);
        $unit = strtolower(substr($m[2], 0, 1));
        $mult = ['' => 1, 'b' => 1, 'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3, 't' => 1024 ** 4][$unit] ?? 1;
        return (int) round($n * $mult);
    }

    public static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return PHP_INT_MAX;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
        };
    }

    /** Safe, short file name for Content-Disposition and storage. */
    public static function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|]+/u', '_', $name) ?? 'file';
        $name = trim($name, ". \t");
        return $name === '' ? 'file' : mb_substr($name, 0, 180);
    }
}
