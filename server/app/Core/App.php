<?php
declare(strict_types=1);

namespace App\Core;

final class App
{
    private static ?string $nonce = null;

    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    public static function run(): void
    {
        $req = Request::capture();
        try {
            if (!Config::installed()) {
                $res = Response::redirect(Request::basePath() . '/install.php');
            } else {
                if (Config::get('security.force_https') && !$req->isSecure()) {
                    // host from the configured URL, never from the (client-controlled) Host header
                    $host = parse_url((string) Config::get('app.url'), PHP_URL_HOST) ?: ($req->server['HTTP_HOST'] ?? '');
                    $res = Response::redirect('https://' . $host . ($req->server['REQUEST_URI'] ?? '/'), 301);
                } else {
                    $router = new Router();
                    require APP_PATH . '/routes.php';
                    $res = $router->dispatch($req);
                }
            }
        } catch (ValidationException $e) {
            $res = self::validationResponse($e, $req);
        } catch (\Throwable $e) {
            if ($e instanceof HttpException && in_array($e->status, [403, 419, 429], true)) {
                \App\Services\ActivityLog::security($e->status, $req);
            }
            $res = ErrorHandler::render($e, $req);
        }
        $res = self::finalize($res, $req);
        $res->send($req->isHead());
    }

    private static function validationResponse(ValidationException $e, Request $req): Response
    {
        if ($req->wantsJson()) {
            if ($e instanceof \App\Services\DuplicateGameException) {
                return Response::json(['ok' => false, 'error' => [
                    'code' => 'duplicate_game', 'message' => $e->getMessage(), 'fields' => $e->errors, 'duplicates' => $e->duplicates,
                ]], 409);
            }
            return Response::json(['ok' => false, 'error' => [
                'code' => 'validation_failed', 'message' => $e->getMessage(), 'fields' => $e->errors,
            ]], 422);
        }
        Session::flash('errors', $e->errors);
        $old = $req->post;
        unset($old['_csrf'], $old['password'], $old['password_confirmation'], $old['current_password']);
        Session::flash('old', $old);
        Session::flash('toast', ['type' => 'error', 'message' => $e->first()]);
        return Response::redirect(self::backUrl($req));
    }

    public static function backUrl(Request $req): string
    {
        $ref = $req->header('Referer');
        $host = $req->server['HTTP_HOST'] ?? '';
        if ($ref && $host && parse_url($ref, PHP_URL_HOST) === parse_url('http://' . $host, PHP_URL_HOST)) {
            return $ref;
        }
        return Request::basePath() . '/';
    }

    private static function finalize(Response $res, Request $req): Response
    {
        $h = &$res->headers;
        $h['X-Content-Type-Options'] = 'nosniff';
        $h['Referrer-Policy'] = 'strict-origin-when-cross-origin';
        $h['X-Frame-Options'] = 'DENY';
        $h['Permissions-Policy'] = 'camera=(), microphone=(), geolocation=(), payment=()';
        $h['Cross-Origin-Opener-Policy'] = 'same-origin';
        if ($req->isSecure()) {
            $h['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        $type = $res->contentType();
        if (str_starts_with($type, 'text/html') && !isset($h['Content-Security-Policy'])) {
            $cdn = '';
            try {
                $cdnUrl = (string) \App\Services\Settings::get('site.cdn_url', '');
                if ($cdnUrl !== '' && ($p = parse_url($cdnUrl)) && isset($p['host'])) {
                    $cdn = ' ' . ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
                }
            } catch (\Throwable) {
            }
            $h['Content-Security-Policy'] = "default-src 'self'; script-src 'self' 'wasm-unsafe-eval' 'nonce-" . self::nonce() . "'{$cdn}; "
                . "style-src 'self' 'unsafe-inline'{$cdn}; img-src 'self' data: blob: https:; media-src 'self' blob: https:{$cdn}; "
                . "font-src 'self'{$cdn}; connect-src 'self'; frame-src https://www.youtube-nocookie.com https://player.vimeo.com; "
                . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
            $h['Cache-Control'] ??= 'no-store, private';
        }
        if ($res instanceof StreamedResponse) {
            return $res;
        }
        if (str_starts_with($type, 'application/json')) {
            $h['Content-Security-Policy'] ??= "default-src 'none'; frame-ancestors 'none'";
            if ($req->isApi() && $req->method() === 'GET' && $res->status === 200) {
                $etag = 'W/"' . md5($res->body) . '"';
                $h['ETag'] = $etag;
                $h['Cache-Control'] ??= 'private, no-cache';
                $inm = $req->header('If-None-Match');
                if ($inm !== null && in_array($etag, array_map('trim', explode(',', $inm)), true)) {
                    $res->status = 304;
                    $res->body = '';
                    unset($h['Content-Type']);
                    return $res;
                }
            }
        }
        $compressible = str_starts_with($type, 'application/json') || str_starts_with($type, 'text/');
        if ($compressible && strlen($res->body) > 1024 && !ini_get('zlib.output_compression')
            && str_contains($req->header('Accept-Encoding') ?? '', 'gzip') && function_exists('gzencode')) {
            $gz = gzencode($res->body, 5);
            if ($gz !== false) {
                $res->body = $gz;
                $h['Content-Encoding'] = 'gzip';
                $h['Vary'] = isset($h['Vary']) ? $h['Vary'] . ', Accept-Encoding' : 'Accept-Encoding';
            }
        }
        if ($res->status !== 204 && $res->status !== 304) {
            $h['Content-Length'] = (string) strlen($res->body);
        }
        return $res;
    }
}
