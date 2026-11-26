<?php
declare(strict_types=1);

namespace App\Core;

final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', Config::get('app.debug') ? '1' : '0');
        ini_set('log_errors', '1');
        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED) {
                Logger::warning('Deprecated: ' . $str, ['file' => $file, 'line' => $line]);
                return true;
            }
            throw new \ErrorException($str, 0, $no, $file, $line);
        });
        set_exception_handler(static function (\Throwable $e): void {
            Logger::error('Uncaught ' . get_class($e) . ': ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, (string) $e . PHP_EOL);
                exit(1);
            }
            self::emitFallback();
        });
        // Fatal errors (memory exhaustion, compile errors…) bypass the handlers above: log them and answer
        // with a clean 500 in the client's format instead of an empty page.
        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }
            try {
                Logger::error('Fatal: ' . $err['message'], ['file' => $err['file'], 'line' => $err['line']]);
            } catch (\Throwable) {
            }
            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                self::emitFallback();
            }
        });
    }

    private static function emitFallback(): void
    {
        if (headers_sent()) {
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (str_contains($path, '/api/') || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => ['code' => 'server_error', 'message' => self::message(500)]], JSON_UNESCAPED_UNICODE);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>500</title>'
            . '<body style="font-family:sans-serif;background:#070816;color:#e2e8f0;display:grid;place-items:center;min-height:100vh">'
            . '<div><h1>500</h1><p>' . htmlspecialchars(self::message(500)) . '</p></div></body></html>';
    }

    public static function code(int $status): string
    {
        return match ($status) {
            400 => 'bad_request', 401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found',
            405 => 'method_not_allowed', 409 => 'conflict', 413 => 'payload_too_large', 415 => 'unsupported_media_type',
            416 => 'range_not_satisfiable', 419 => 'csrf_token_mismatch', 422 => 'validation_failed',
            429 => 'too_many_requests', 503 => 'service_unavailable', default => $status >= 500 ? 'server_error' : 'error',
        };
    }

    public static function message(int $status): string
    {
        return match ($status) {
            401 => 'يجب تسجيل الدخول أولاً',
            403 => 'ليست لديك صلاحية للوصول إلى هذا القسم',
            404 => 'الصفحة أو العنصر المطلوب غير موجود',
            419 => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة وحاول مجدداً',
            429 => 'طلبات كثيرة خلال وقت قصير، حاول بعد قليل',
            503 => 'الخدمة متوقفة مؤقتاً للصيانة',
            default => $status >= 500 ? 'حدث خطأ غير متوقع في الخادم، تم تسجيله وسنعالجه' : 'تعذر تنفيذ الطلب',
        };
    }

    public static function render(\Throwable $e, Request $req): Response
    {
        $status = $e instanceof HttpException ? $e->status : 500;
        $headers = $e instanceof HttpException ? $e->headers : [];
        if ($status >= 500) {
            Logger::error(get_class($e) . ': ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
        }
        $message = ($e instanceof HttpException && $e->getMessage() !== '') ? $e->getMessage() : self::message($status);
        $code = ($e instanceof HttpException && $e->errorCode !== '') ? $e->errorCode : self::code($status);

        if ($req->wantsJson()) {
            $payload = ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
            if (Config::get('app.debug') && $status >= 500) {
                $payload['error']['debug'] = $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
            }
            return Response::json($payload, $status, $headers);
        }
        try {
            $html = View::render('errors/error', [
                'status' => $status, 'message' => $message,
                'title' => $status . ' — ' . Config::get('app.name'),
                'debug' => Config::get('app.debug') && $status >= 500 ? (string) $e : null,
            ], 'layouts/app');
        } catch (\Throwable) {
            $html = '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>' . $status . '</title>'
                . '<body style="font-family:sans-serif;background:#070816;color:#e2e8f0;display:grid;place-items:center;min-height:100vh">'
                . '<div><h1>' . $status . '</h1><p>' . htmlspecialchars($message) . '</p></div></body></html>';
        }
        return Response::html($html, $status, $headers);
    }
}
