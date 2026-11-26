<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Serves protected files with HTTP Range support (resumable downloads, download managers),
 * ETag / If-Range validation, and optional X-Sendfile (Apache) or X-Accel-Redirect (nginx).
 */
final class FileStreamer
{
    public static function response(Request $req, string $path, string $downloadName, ?string $mime = null, ?callable $onStart = null, ?string $internalUri = null): Response
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new HttpException(404, 'الملف غير موجود على الخادم');
        }
        clearstatcache(true, $path);
        $size = (int) filesize($path);
        $mtime = (int) filemtime($path);
        $etag = '"' . dechex($size) . '-' . dechex($mtime) . '"';
        $headers = [
            'Content-Type' => $mime ?: 'application/octet-stream',
            'Content-Disposition' => self::disposition($downloadName),
            'Accept-Ranges' => 'bytes',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
            'Cache-Control' => 'private, no-transform, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        $start = 0;
        $end = max(0, $size - 1);
        $status = 200;
        $range = $req->header('Range');
        $ifRange = $req->header('If-Range');
        if ($range !== null && $size > 0 && ($ifRange === null || trim($ifRange) === $etag)) {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) || ($m[1] === '' && $m[2] === '')) {
                return new Response('', 416, ['Content-Range' => "bytes */{$size}"]);
            }
            if ($m[1] === '') {
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                if ($m[2] !== '') {
                    $end = min((int) $m[2], $size - 1);
                }
            }
            if ($start > $end || $start >= $size) {
                return new Response('', 416, ['Content-Range' => "bytes */{$size}"]);
            }
            $status = 206;
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }
        $length = $size === 0 ? 0 : $end - $start + 1;
        $headers['Content-Length'] = (string) $length;

        if ($onStart) {
            $onStart($start, $end, $size);
        }

        $mode = Config::get('storage.x_sendfile');
        if ($mode === 'apache') {
            unset($headers['Content-Length'], $headers['Content-Range']);
            $headers['X-Sendfile'] = $path;
            return new Response('', 200, $headers);
        }
        if ($mode === 'nginx' && $internalUri !== null) {
            unset($headers['Content-Length'], $headers['Content-Range']);
            $headers['X-Accel-Redirect'] = $internalUri;
            return new Response('', 200, $headers);
        }

        return new StreamedResponse(static function () use ($path, $start, $length): void {
            @set_time_limit(0);
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $fp = fopen($path, 'rb');
            if (!$fp) {
                return;
            }
            fseek($fp, $start);
            $remaining = $length;
            while ($remaining > 0 && !feof($fp)) {
                $buf = fread($fp, (int) min(1048576, $remaining));
                if ($buf === false || $buf === '') {
                    break;
                }
                echo $buf;
                flush();
                $remaining -= strlen($buf);
                if (connection_aborted()) {
                    break;
                }
            }
            fclose($fp);
        }, $status, $headers);
    }

    public static function disposition(string $name, string $type = 'attachment'): string
    {
        $safe = Str::safeFilename($name);
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $safe) ?: 'file';
        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $type, str_replace('"', '', $ascii), rawurlencode($safe));
    }
}
