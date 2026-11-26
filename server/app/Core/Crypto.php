<?php
declare(strict_types=1);

namespace App\Core;

/** Secret-at-rest encryption (libsodium secretbox) and HMAC signing with the application key. */
final class Crypto
{
    private static function key(): string
    {
        $appKey = (string) Config::get('app.key');
        if ($appKey === '') {
            throw new \RuntimeException('app.key is not configured');
        }
        return sodium_crypto_generichash($appKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'enc:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(?string $value): ?string
    {
        if ($value === null || !str_starts_with($value, 'enc:')) {
            return $value;
        }
        $raw = base64_decode(substr($value, 4), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        return $plain === false ? null : $plain;
    }

    public static function token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function sign(string $data): string
    {
        return hash_hmac('sha256', $data, (string) Config::get('app.key'));
    }

    public static function verify(string $data, string $signature): bool
    {
        return hash_equals(self::sign($data), $signature);
    }
}
