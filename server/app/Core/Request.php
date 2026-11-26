<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public static ?Request $current = null;
    private array $routeParams = [];
    private ?array $jsonBody = null;

    public function __construct(
        public array $query,
        public array $post,
        public array $files,
        public array $server,
        public array $cookies
    ) {
    }

    public static function capture(): self
    {
        return self::$current = new self($_GET, $_POST, $_FILES, $_SERVER, $_COOKIE);
    }

    /** URL prefix when the app lives in a sub-directory (e.g. "/library"), "" otherwise. */
    public static function basePath(): string
    {
        static $base = null;
        if ($base === null) {
            $configured = Config::get('app.base_path');
            if (is_string($configured)) {
                $base = rtrim($configured, '/');
            } else {
                $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
                // whole project uploaded as the web root: server/.htaccess forwards into public/ and flags it
                $forwarded = !empty($_SERVER['UPSL_ROOT_FORWARD']) || !empty($_SERVER['REDIRECT_UPSL_ROOT_FORWARD']) || !empty($_SERVER['REDIRECT_REDIRECT_UPSL_ROOT_FORWARD']);
                if ($forwarded && str_ends_with($dir, '/public')) {
                    $dir = substr($dir, 0, -7) ?: '/';
                }
                $base = ($dir === '/' || $dir === '.' || $dir === '' || PHP_SAPI === 'cli') ? '' : rtrim($dir, '/');
            }
        }
        return $base;
    }

    public function method(): string
    {
        $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST') {
            $override = strtoupper((string) ($this->post['_method'] ?? $this->header('X-HTTP-Method-Override') ?? ''));
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }
        return $method === 'HEAD' ? 'GET' : $method;
    }

    public function isHead(): bool
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? '') === 'HEAD';
    }

    public function path(): string
    {
        $path = parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);
        $base = self::basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, 10);
        }
        return '/' . trim($path, '/');
    }

    public function fullPath(): string
    {
        $qs = $this->server['QUERY_STRING'] ?? '';
        return $this->path() . ($qs !== '' ? '?' . $qs : '');
    }

    public function json(): array
    {
        if ($this->jsonBody === null) {
            $this->jsonBody = [];
            if (str_contains(strtolower($this->header('Content-Type') ?? ''), 'application/json')) {
                $decoded = json_decode((string) file_get_contents('php://input'), true);
                $this->jsonBody = is_array($decoded) ? $decoded : [];
            }
        }
        return $this->jsonBody;
    }

    public function all(): array
    {
        return array_replace($this->query, $this->post, $this->json());
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function only(array $keys): array
    {
        $all = $this->all();
        return array_intersect_key($all, array_flip($keys));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->input($key);
        if ($v === null) {
            return $default;
        }
        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function file(string $key): ?UploadedFile
    {
        return isset($this->files[$key]) ? UploadedFile::fromArray($this->files[$key]) : null;
    }

    /** @return UploadedFile[] */
    public function fileList(string $key): array
    {
        $f = $this->files[$key] ?? null;
        if (!$f) {
            return [];
        }
        if (!is_array($f['name'])) {
            $one = UploadedFile::fromArray($f);
            return $one ? [$one] : [];
        }
        $out = [];
        foreach ($f['name'] as $i => $name) {
            $one = UploadedFile::fromArray([
                'name' => $name, 'tmp_name' => $f['tmp_name'][$i], 'size' => $f['size'][$i],
                'error' => $f['error'][$i], 'type' => $f['type'][$i] ?? null,
            ]);
            if ($one) {
                $out[] = $one;
            }
        }
        return $out;
    }

    public function header(string $name): ?string
    {
        $upper = strtoupper(str_replace('-', '_', $name));
        if ($upper === 'CONTENT_TYPE' || $upper === 'CONTENT_LENGTH') {
            return $this->server[$upper] ?? $this->server['HTTP_' . $upper] ?? null;
        }
        return $this->server['HTTP_' . $upper] ?? null;
    }

    public function bearerToken(): ?string
    {
        $h = $this->header('Authorization') ?? ($this->server['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
        if ($h && preg_match('/^Bearer\s+([A-Za-z0-9._\-]+)$/i', trim($h), $m)) {
            return $m[1];
        }
        $alt = $this->header('X-Api-Token');
        return ($alt && preg_match('/^[A-Za-z0-9._\-]+$/', $alt)) ? $alt : null;
    }

    public function ip(): string
    {
        $remote = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
        $trusted = (array) Config::get('security.trusted_proxies', []);
        if ($trusted && in_array($remote, $trusted, true) && !empty($this->server['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', $this->server['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return $remote;
    }

    public function isSecure(): bool
    {
        if (!empty($this->server['HTTPS']) && strtolower((string) $this->server['HTTPS']) !== 'off') {
            return true;
        }
        $trusted = (array) Config::get('security.trusted_proxies', []);
        if ($trusted && in_array($this->server['REMOTE_ADDR'] ?? '', $trusted, true)) {
            return strtolower($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        }
        return (int) ($this->server['SERVER_PORT'] ?? 80) === 443;
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path(), '/api/');
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('X-Requested-With') ?? '') === 'xmlhttprequest';
    }

    public function wantsJson(): bool
    {
        return $this->isApi() || $this->isAjax() || str_contains($this->header('Accept') ?? '', 'application/json');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 250);
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }
}
