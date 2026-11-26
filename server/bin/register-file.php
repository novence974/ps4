<?php
declare(strict_types=1);
/**
 * Attach a file that is already on the server (copied by SFTP/rsync) to a game — ideal for very large files.
 *   php bin/register-file.php --game=12 --file=/data/incoming/game.pkg [--type=game|update|dlc|data|save|other]
 *        [--access=registered|public|verified|moderator|admin] [--label="..."] [--region=EUR] [--version=1.05] [--move]
 */
require __DIR__ . '/_cli.php';
cli_require_installed();

use App\Services\FileService;

$o = cli_opts($argv);
if (empty($o['game']) || empty($o['file'])) {
    fwrite(STDERR, "Usage: php bin/register-file.php --game=ID --file=/path/to/file [--type=game] [--access=registered] [--label=...] [--move]\n");
    exit(1);
}
$path = (string) $o['file'];
echo "Hashing and copying " . basename($path) . " (" . round(filesize($path) / 1048576, 1) . " MB)…\n";
$id = FileService::importLocal((int) $o['game'], $path, basename($path), [
    'file_type' => $o['type'] ?? 'game', 'access_level' => $o['access'] ?? 'registered', 'label' => $o['label'] ?? null,
    'region' => $o['region'] ?? null, 'version_label' => $o['version'] ?? null,
], null, isset($o['move']));
echo "Registered as file #{$id}.\n";
