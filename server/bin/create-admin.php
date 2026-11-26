<?php
declare(strict_types=1);
/** Creates (or promotes) a Super Admin account:  php bin/create-admin.php --user=name --email=a@b.c --pass='...' */
require __DIR__ . '/_cli.php';
cli_require_installed();

use App\Core\DB;
use App\Core\ValidationException;
use App\Services\AuthService;
use App\Services\UserService;

$o = cli_opts($argv);
$user = $o['user'] ?? cli_ask('Username');
$existing = DB::one('SELECT id FROM users WHERE username = ? OR email = ?', [$user, $user]);
if ($existing) {
    DB::update('users', ['role_id' => 1, 'status' => 'active', 'locked_until' => null, 'failed_logins' => 0], 'id = ?', [(int) $existing['id']]);
    if (!empty($o['pass'])) {
        AuthService::assertStrong((string) $o['pass']);
        DB::update('users', ['password_hash' => AuthService::hash((string) $o['pass'])], 'id = ?', [(int) $existing['id']]);
        AuthService::revokeAllTokens((int) $existing['id']);
    }
    echo "User #{$existing['id']} is now an active Super Admin.\n";
    exit(0);
}
try {
    $id = UserService::create(['username' => $user, 'email' => $o['email'] ?? cli_ask('E-mail'), 'password' => $o['pass'] ?? cli_ask('Password', '', true)], 1, true);
    echo "Super Admin created (#{$id}).\n";
} catch (ValidationException $e) {
    foreach ($e->errors as $k => $v) {
        fwrite(STDERR, "{$k}: {$v}\n");
    }
    exit(1);
}
