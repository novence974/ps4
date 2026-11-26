<?php
declare(strict_types=1);

/**
 * Tiny dependency-free test harness for ULTIMATE PS LIBRARY.
 * Runs over real HTTP against a running server and may also inspect the database
 * through the application bootstrap (the tests must run on the server machine).
 */

final class TestFailure extends RuntimeException
{
}

final class Http
{
    public array $cookies = [];

    public function __construct(public string $base)
    {
    }

    /** @return array{status:int,headers:array,body:string,json:?array,time:float} */
    public function request(string $method, string $path, array $o = []): array
    {
        $url = preg_match('#^https?://#', $path) ? $path : rtrim($this->base, '/') . $path;
        if (!empty($o['query'])) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($o['query']);
        }
        $ch = curl_init($url);
        $headers = ['Accept: ' . ($o['accept'] ?? 'application/json')];
        foreach ($o['headers'] ?? [] as $k => $v) {
            $headers[] = "$k: $v";
        }
        if (!empty($o['token'])) {
            $headers[] = 'Authorization: Bearer ' . $o['token'];
        }
        if (array_key_exists('json', $o)) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($o['json'], JSON_UNESCAPED_UNICODE));
        } elseif (isset($o['multipart'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $o['multipart']);
        } elseif (isset($o['form'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($o['form']));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif (isset($o['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $o['body']);
            $headers[] = 'Content-Type: ' . ($o['content_type'] ?? 'application/octet-stream');
        }
        if (!empty($o['cookies']) && $this->cookies) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(static fn ($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_ENCODING => $o['encoding'] ?? '',
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
                $p = explode(':', $line, 2);
                if (count($p) === 2) {
                    $name = strtolower(trim($p[0]));
                    $respHeaders[$name][] = trim($p[1]);
                    if ($name === 'set-cookie' && preg_match('/^([^=]+)=([^;]*)/', trim($p[1]), $m)) {
                        $this->cookies[$m[1]] = $m[2];
                    }
                }
                return strlen($line);
            },
        ]);
        $t = microtime(true);
        $body = curl_exec($ch);
        $time = microtime(true) - $t;
        if ($body === false) {
            throw new TestFailure('HTTP error: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = json_decode((string) $body, true);
        return ['status' => $status, 'headers' => $respHeaders, 'body' => (string) $body, 'json' => is_array($json) ? $json : null, 'time' => $time];
    }

    public function get(string $p, array $o = []): array { return $this->request('GET', $p, $o); }
    public function post(string $p, array $o = []): array { return $this->request('POST', $p, $o); }
    public function put(string $p, array $o = []): array { return $this->request('PUT', $p, $o); }
    public function delete(string $p, array $o = []): array { return $this->request('DELETE', $p, $o); }
}

function header_value(array $r, string $name): ?string
{
    return $r['headers'][strtolower($name)][0] ?? null;
}

function check(bool $cond, string $message): void
{
    if (!$cond) {
        throw new TestFailure($message);
    }
}

function expect_status(array $r, int $status, string $context = ''): array
{
    if ($r['status'] !== $status) {
        $snippet = mb_substr($r['body'], 0, 300);
        throw new TestFailure(($context ? $context . ': ' : '') . "expected HTTP {$status}, got {$r['status']} — {$snippet}");
    }
    return $r;
}

function expect_ok(array $r, string $context = '', int $status = 200): array
{
    expect_status($r, $status, $context);
    check(($r['json']['ok'] ?? false) === true, ($context ? $context . ': ' : '') . 'JSON ok=true expected');
    return $r['json']['data'] ?? [];
}

function make_png(int $w = 640, int $h = 960, ?string $append = null): string
{
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y += 8) {
        imagefilledrectangle($im, 0, $y, $w, $y + 8, imagecolorallocate($im, ($y * 3) % 255, 80, 200));
    }
    $file = tempnam(sys_get_temp_dir(), 'png') . '.png';
    imagepng($im, $file);
    imagedestroy($im);
    if ($append !== null) {
        file_put_contents($file, $append, FILE_APPEND);
    }
    return $file;
}

final class Suite
{
    public array $results = [];
    /** @var callable|null runs before every test (e.g. reset rate-limit counters: all test traffic comes from one IP) */
    public $beforeEach = null;
    private array $tests = [];

    public function add(string $group, string $name, callable $fn): void
    {
        $this->tests[] = [$group, $name, $fn];
    }

    public function run(?string $filter = null): bool
    {
        $pass = 0;
        $fail = 0;
        $group = null;
        foreach ($this->tests as [$g, $name, $fn]) {
            if ($filter && stripos($g . ' ' . $name, $filter) === false) {
                continue;
            }
            if ($g !== $group) {
                echo PHP_EOL . "\033[1m{$g}\033[0m" . PHP_EOL;
                $group = $g;
            }
            $t = microtime(true);
            try {
                if ($this->beforeEach) {
                    ($this->beforeEach)();
                }
                $fn();
                $ms = (microtime(true) - $t) * 1000;
                printf("  \033[32m✔\033[0m %s \033[2m(%.0f ms)\033[0m\n", $name, $ms);
                $this->results[] = [$g, $name, true, '', $ms];
                $pass++;
            } catch (Throwable $e) {
                $ms = (microtime(true) - $t) * 1000;
                printf("  \033[31m✘ %s\033[0m\n    → %s\n", $name, $e->getMessage() . ($e instanceof TestFailure ? '' : ' @ ' . basename($e->getFile()) . ':' . $e->getLine()));
                $this->results[] = [$g, $name, false, $e->getMessage(), $ms];
                $fail++;
            }
        }
        printf("\n%s%d passed, %d failed\033[0m\n", $fail ? "\033[31m" : "\033[32m", $pass, $fail);
        return $fail === 0;
    }
}
