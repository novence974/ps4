<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\Settings;

/**
 * Mail delivery: smtp (built-in client with SSL/STARTTLS + AUTH LOGIN), mail (PHP mail()) or log
 * (writes .eml files to storage/mail, useful while SMTP is not configured yet).
 */
final class Mailer
{
    public static function config(): array
    {
        $base = (array) Config::get('mail', []);
        $get = static fn (string $k, $d) => Settings::get('mail.' . $k, $base[$k] ?? $d);
        return [
            'driver' => (string) $get('driver', 'log'),
            'host' => (string) $get('host', ''),
            'port' => (int) $get('port', 587),
            'encryption' => (string) $get('encryption', 'tls'),
            'username' => (string) $get('username', ''),
            'password' => (string) (Crypto::decrypt((string) $get('password', '')) ?? ''),
            'from_address' => (string) $get('from_address', 'no-reply@localhost'),
            'from_name' => (string) $get('from_name', (string) Settings::get('site.name', 'ULTIMATE PS LIBRARY')),
        ];
    }

    public static function send(string $to, string $subject, string $html, ?string $text = null): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $c = self::config();
        $text ??= trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/h\d)>/i', "\n", $html) ?? ''), ENT_QUOTES, 'UTF-8'));
        [$headers, $body] = self::build($c, $to, $subject, $html, $text);
        try {
            $ok = match ($c['driver']) {
                'smtp' => self::smtp($c, $to, $headers . "\r\n" . $body),
                'mail' => mail($to, self::encode($subject), $body, preg_replace('/^(To|Subject):.*\r\n/m', '', $headers) ?? ''),
                default => self::writeLog($headers . "\r\n" . $body),
            };
            if (!$ok) {
                Logger::error('Mail not sent', ['to' => $to, 'driver' => $c['driver']]);
            }
            return (bool) $ok;
        } catch (\Throwable $e) {
            Logger::error('Mail failed: ' . $e->getMessage(), ['to' => $to, 'driver' => $c['driver']]);
            return false;
        }
    }

    private static function encode(string $s): string
    {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }

    private static function build(array $c, string $to, string $subject, string $html, string $text): array
    {
        $boundary = 'b' . bin2hex(random_bytes(8));
        $host = parse_url((string) Config::get('app.url'), PHP_URL_HOST) ?: 'localhost';
        $headers = implode("\r\n", [
            'From: ' . self::encode($c['from_name']) . ' <' . $c['from_address'] . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encode($subject),
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ]) . "\r\n";
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--{$boundary}--\r\n";
        return [$headers, $body];
    }

    private static function writeLog(string $message): bool
    {
        $dir = STORAGE_PATH . '/mail';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return file_put_contents($dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml', $message) !== false;
    }

    private static function smtp(array $c, string $to, string $data): bool
    {
        if ($c['host'] === '') {
            throw new \RuntimeException('SMTP host is not configured');
        }
        $remote = ($c['encryption'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'];
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new \RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, 20);
        $expect = static function (array $codes) use ($fp): string {
            $resp = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $resp .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            if (!in_array((int) substr($resp, 0, 3), $codes, true)) {
                throw new \RuntimeException('SMTP: ' . trim($resp));
            }
            return $resp;
        };
        $cmd = static function (string $line, array $codes) use ($fp, $expect): string {
            fwrite($fp, $line . "\r\n");
            return $expect($codes);
        };
        try {
            $ehloHost = parse_url((string) Config::get('app.url'), PHP_URL_HOST) ?: 'localhost';
            $expect([220]);
            $cmd('EHLO ' . $ehloHost, [250]);
            if ($c['encryption'] === 'tls') {
                $cmd('STARTTLS', [220]);
                $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
                if (!stream_socket_enable_crypto($fp, true, $method)) {
                    throw new \RuntimeException('SMTP STARTTLS negotiation failed');
                }
                $cmd('EHLO ' . $ehloHost, [250]);
            }
            if ($c['username'] !== '') {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($c['username']), [334]);
                $cmd(base64_encode($c['password']), [235]);
            }
            $cmd('MAIL FROM:<' . $c['from_address'] . '>', [250]);
            $cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $cmd('DATA', [354]);
            $data = str_replace(["\r\n", "\r"], "\n", $data);
            $data = preg_replace('/^\./m', '..', $data) ?? $data;
            fwrite($fp, str_replace("\n", "\r\n", $data) . "\r\n.\r\n");
            $expect([250]);
            $cmd('QUIT', [221]);
            return true;
        } finally {
            fclose($fp);
        }
    }
}
