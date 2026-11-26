<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\AuthService;
use App\Services\Settings;

/**
 * Middleware names used in routes.php:
 *  web            session + CSRF for unsafe methods + maintenance mode
 *  api            JSON API: CORS, bearer token or cookie session (+CSRF), rate limiting, maintenance
 *  auth           requires a signed-in user
 *  guest          only for signed-out visitors (login/register pages)
 *  admin          requires the admin.access permission
 *  perm:a|b       requires at least one of the permissions
 *  verified       requires a verified e-mail when the setting is enabled
 *  throttle:n,max,seconds
 */
final class Middleware
{
    public static function resolve(string $spec): callable
    {
        [$name, $arg] = array_pad(explode(':', $spec, 2), 2, '');
        return match ($name) {
            'web' => static fn (Request $r, callable $next) => self::web($r, $next),
            'api' => static fn (Request $r, callable $next) => self::api($r, $next),
            'auth' => static fn (Request $r, callable $next) => Auth::check() ? $next($r) : self::unauthenticated($r),
            'guest' => static fn (Request $r, callable $next) => Auth::check() ? Response::redirect(url('/account')) : $next($r),
            'admin' => static function (Request $r, callable $next) {
                if (!Auth::check()) {
                    return self::unauthenticated($r);
                }
                if (!Auth::can('admin.access')) {
                    throw new HttpException(403);
                }
                return $next($r);
            },
            'perm' => static function (Request $r, callable $next) use ($arg) {
                if (!Auth::check()) {
                    return self::unauthenticated($r);
                }
                foreach (explode('|', $arg) as $p) {
                    if (Auth::can($p)) {
                        return $next($r);
                    }
                }
                throw new HttpException(403);
            },
            'verified' => static function (Request $r, callable $next) {
                if (Auth::check() && !Auth::isVerified() && Settings::get('auth.email_verification', true)) {
                    throw new HttpException(403, 'يجب تأكيد بريدك الإلكتروني أولاً', [], 'email_not_verified');
                }
                return $next($r);
            },
            'throttle' => static function (Request $r, callable $next) use ($arg) {
                [$bucket, $max, $window] = array_pad(explode(',', $arg), 3, null);
                [$ok, , $retry] = RateLimiter::attempt('th:' . $bucket . ':' . $r->ip(), (int) ($max ?: 10), (int) ($window ?: 60));
                if (!$ok) {
                    throw new HttpException(429, ErrorHandler::message(429), ['Retry-After' => (string) $retry]);
                }
                return $next($r);
            },
            default => throw new \LogicException('Unknown middleware: ' . $name),
        };
    }

    private static function unauthenticated(Request $r): Response
    {
        if ($r->wantsJson()) {
            throw new HttpException(401);
        }
        Session::set('intended', $r->fullPath());
        return Response::redirect(url('/login'));
    }

    private static function web(Request $r, callable $next): Response
    {
        Session::start($r);
        Auth::resolveSession();
        if (in_array($r->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !Csrf::verify($r)) {
            throw new HttpException(419);
        }
        if (Settings::get('site.maintenance', false) && !Auth::can('admin.access')
            && !in_array($r->path(), ['/login', '/logout'], true)) {
            throw new HttpException(503, (string) Settings::get('site.maintenance_message', 'المكتبة تحت الصيانة حالياً، نعود قريباً'));
        }
        return $next($r);
    }

    private static function api(Request $r, callable $next): Response
    {
        $cors = self::corsHeaders($r);
        if ($r->method() === 'OPTIONS') {
            return new Response('', 204, $cors + ['Access-Control-Max-Age' => '600']);
        }
        if (!Settings::get('api.enabled', true) && !str_starts_with($r->path(), '/api/v1/status')) {
            throw new HttpException(503, 'واجهة API معطلة من لوحة التحكم');
        }

        $token = $r->bearerToken();
        if ($token !== null) {
            $resolved = AuthService::resolveToken($token, $r->ip());
            if (!$resolved) {
                throw new HttpException(401, 'رمز الدخول غير صالح أو منتهي الصلاحية', ['WWW-Authenticate' => 'Bearer'], 'invalid_token');
            }
            Auth::set($resolved['user'], 'token', $resolved['token_id']);
        } elseif (isset($r->cookies[(string) Config::get('session.name', 'UPSLSESS')])) {
            Session::startReadOnly($r);
            Auth::resolveSession();
            if (Auth::check() && in_array($r->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !Csrf::verify($r)) {
                throw new HttpException(419);
            }
        }

        if (Settings::get('site.maintenance', false) && !Auth::can('admin.access')
            && !in_array($r->path(), ['/api/v1/status', '/api/v1/auth/login'], true)) {
            throw new HttpException(503, (string) Settings::get('site.maintenance_message', 'المكتبة تحت الصيانة حالياً'));
        }

        $limit = max(10, (int) Settings::get('api.rate_limit_per_minute', 120));
        $isChunk = str_ends_with($r->path(), '/uploads/chunk') && Auth::can('files.upload');
        $remaining = null;
        if (!$isChunk) {
            $key = 'api:' . (Auth::id() ? 'u' . Auth::id() : 'ip' . $r->ip());
            [$ok, $remaining, $retry] = RateLimiter::attempt($key, $limit, 60);
            if (!$ok) {
                throw new HttpException(429, ErrorHandler::message(429), $cors + ['Retry-After' => (string) $retry]);
            }
        }

        $response = $next($r);
        foreach ($cors as $k => $v) {
            $response->headers[$k] = $v;
        }
        if ($remaining !== null) {
            $response->headers['X-RateLimit-Limit'] = (string) $limit;
            $response->headers['X-RateLimit-Remaining'] = (string) $remaining;
        }
        return $response;
    }

    private static function corsHeaders(Request $r): array
    {
        $origin = $r->header('Origin');
        if (!$origin) {
            return [];
        }
        $allowed = array_filter(array_map('trim', explode(',', (string) Settings::get('api.cors_origins', ''))));
        if (!in_array('*', $allowed, true) && !in_array(rtrim($origin, '/'), $allowed, true)) {
            return [];
        }
        return [
            'Access-Control-Allow-Origin' => in_array('*', $allowed, true) ? '*' : $origin,
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Requested-With, X-Upload-Id, X-Chunk-Index, X-Chunk-Sha256, X-Api-Token',
            'Access-Control-Expose-Headers' => 'X-RateLimit-Limit, X-RateLimit-Remaining, Retry-After, ETag',
            'Vary' => 'Origin',
        ];
    }
}
