<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $t = Session::get('_csrf');
        if (!is_string($t) || strlen($t) !== 64) {
            $t = bin2hex(random_bytes(32));
            Session::set('_csrf', $t);
        }
        return $t;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::token() . '">';
    }

    public static function verify(Request $r): bool
    {
        $expected = Session::get('_csrf');
        $given = $r->post['_csrf'] ?? $r->header('X-CSRF-Token') ?? ($r->json()['_csrf'] ?? null);
        return is_string($expected) && is_string($given) && $expected !== '' && hash_equals($expected, $given);
    }
}
