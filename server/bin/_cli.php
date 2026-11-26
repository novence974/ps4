<?php
declare(strict_types=1);

// Shared bootstrap for command-line tools.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';
App\Core\Auth::actAsSystem();

function cli_opts(array $argv): array
{
    $o = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $a, $m)) {
            $o[$m[1]] = $m[2] ?? true;
        }
    }
    return $o;
}

function cli_ask(string $q, ?string $default = null, bool $secret = false): string
{
    $label = $q . ($default !== null && $default !== '' ? " [{$default}]" : '') . ': ';
    if ($secret && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        echo $label;
        shell_exec('stty -echo');
        $v = trim((string) fgets(STDIN));
        shell_exec('stty echo');
        echo PHP_EOL;
    } else {
        $v = trim((string) readline($label));
    }
    return $v === '' ? (string) $default : $v;
}

function cli_require_installed(): void
{
    if (!App\Core\Config::installed()) {
        fwrite(STDERR, "Not installed yet. Run: php bin/install.php\n");
        exit(1);
    }
}
