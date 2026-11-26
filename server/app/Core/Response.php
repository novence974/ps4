<?php
declare(strict_types=1);

namespace App\Core;

class Response
{
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = []
    ) {
    }

    public static function html(string $html, int $status = 200, array $headers = []): static
    {
        return new static($html, $status, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): static
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        return new static($body === false ? '{}' : $body, $status, ['Content-Type' => 'application/json; charset=UTF-8'] + $headers);
    }

    public static function text(string $text, int $status = 200, array $headers = []): static
    {
        return new static($text, $status, ['Content-Type' => 'text/plain; charset=UTF-8'] + $headers);
    }

    public static function redirect(string $url, int $status = 302): static
    {
        return new static('', $status, ['Location' => $url]);
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function contentType(): string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, 'Content-Type') === 0) {
                return strtolower((string) $v);
            }
        }
        return '';
    }

    public function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('X-Powered-By');
        // Apache mod_php turns status codes it does not know (419) into 500; send a reason phrase it can pass through
        if ($this->status === 419) {
            header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 419 Page Expired', true, 419);
        } else {
            http_response_code($this->status);
        }
        foreach ($this->headers as $name => $value) {
            foreach ((array) $value as $v) {
                header($name . ': ' . str_replace(["\r", "\n"], '', (string) $v), false);
            }
        }
    }

    public function send(bool $headOnly = false): void
    {
        $this->sendHeaders();
        if (!$headOnly && $this->status !== 204 && $this->status !== 304) {
            echo $this->body;
        }
    }
}
