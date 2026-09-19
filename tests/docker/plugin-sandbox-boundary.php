<?php

declare(strict_types=1);

use Stashd\PluginRuntime\Package\PackageManager;
use Stashd\PluginRuntime\Runner\PluginRunner;

require '/var/www/html/vendor/autoload.php';

function boundaryRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            boundaryRemove($path . '/' . $entry);
        }
    }

    @rmdir($path);
}

function boundaryAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$root = '/data/plugin-sandbox-boundary';
$source = $root . '/source';
$stage = $root . '/stage';
$canary = '/data/plugin-sandbox-boundary-canary';
$networkPort = random_int(38000, 38999);
$networkServer = @stream_socket_server("tcp://127.0.0.1:{$networkPort}", $networkError, $networkMessage);

if (! is_resource($networkServer)) {
    throw new RuntimeException("network sensitivity server could not start: {$networkMessage}");
}

boundaryRemove($root);
file_put_contents($canary, 'host-only');
mkdir($source, 0700, true);
mkdir($stage, 0700, true);
mkdir($root . '/sdk', 0700, true);

file_put_contents($source . '/plugin.json', json_encode([
    'id' => 'boundary',
    'name' => 'Boundary probe',
    'version' => '1.0.0',
    'runtime' => 'php',
    'api_version' => '0.2',
    'entrypoint' => 'plugin.php',
], JSON_THROW_ON_ERROR));
file_put_contents($source . '/plugin.php', str_replace('__BOUNDARY_NETWORK_PORT__', (string) $networkPort, <<<'PHP'
<?php

function boundaryWrite(array $message): void
{
    $json = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $frame = pack('N', strlen($json)) . $json;

    while ($frame !== '') {
        $written = fwrite(STDOUT, $frame);

        if ($written === false || $written === 0) {
            exit(20);
        }

        $frame = substr($frame, $written);
    }

    fflush(STDOUT);
}

function boundaryRead(): array
{
    $header = fread(STDIN, 4);

    if (! is_string($header) || strlen($header) !== 4) {
        exit(21);
    }

    $length = unpack('Nlength', $header)['length'];
    $json = '';

    while (strlen($json) < $length) {
        $chunk = fread(STDIN, $length - strlen($json));

        if (! is_string($chunk) || $chunk === '') {
            exit(22);
        }

        $json .= $chunk;
    }

    $message = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    return is_array($message) ? $message : exit(23);
}

boundaryWrite([
    'protocol' => 1,
    'id' => 'plugin-hello',
    'kind' => 'request',
    'method' => 'hello',
    'params' => ['min' => 1, 'max' => 1],
]);

$hello = boundaryRead();
if (($hello['kind'] ?? null) !== 'response') {
    exit(24);
}

$request = boundaryRead();
$capEff = 0;
if (preg_match('/^CapEff:\s*([0-9a-f]+)/m', file_get_contents('/proc/self/status'), $capMatch) === 1) {
    $capEff = hexdec(substr($capMatch[1], -8));
}

$packageWrite = @file_put_contents('/plugin/should-not-write', 'blocked');
$stageWrite = @file_put_contents('/staging/allowed.txt', 'allowed');
$network = @fsockopen('127.0.0.1', __BOUNDARY_NETWORK_PORT__, $networkError, $networkMessage, 0.2);
$networkVisible = is_resource($network);
if ($networkVisible) {
    fclose($network);
}

boundaryWrite([
    'protocol' => 1,
    'id' => $request['id'],
    'kind' => 'response',
    'result' => [
        'data_visible' => is_file('/data/plugin-sandbox-boundary-canary'),
        'app_visible' => is_file('/var/www/html/composer.json'),
        'package_write' => $packageWrite !== false,
        'stage_write' => $stageWrite === 7,
        'secret_visible' => getenv('SIGNING_KEY') !== false,
        'network_visible' => $networkVisible,
        'cap_sys_admin' => ($capEff & (1 << 21)) !== 0,
    ],
]);
PHP
));

try {
    $manager = new PackageManager($root, ['0.2', '0.1'], 'amd64');
    $manager->link('boundary', $source);
    $process = (new PluginRunner($manager, sdkRoot: $root . '/sdk'))->start('boundary', $stage);
    $result = $process->invoke('boundary.probe', [], static fn (array $message): array => []);
    $exit = $process->close();

    boundaryAssert(($result['data_visible'] ?? null) === false, 'sandbox exposed an ungranted data path');
    boundaryAssert(($result['app_visible'] ?? null) === false, 'sandbox exposed the application root');
    boundaryAssert(($result['package_write'] ?? null) === false, 'sandbox plugin package was writable');
    boundaryAssert(($result['stage_write'] ?? null) === true, 'sandbox staging grant was not writable');
    boundaryAssert(($result['secret_visible'] ?? null) === false, 'sandbox inherited the host secret');
    boundaryAssert(($result['network_visible'] ?? null) === false, 'sandbox retained network access');
    boundaryAssert(($result['cap_sys_admin'] ?? null) === false, 'sandbox retained CAP_SYS_ADMIN');
    boundaryAssert(is_file($stage . '/allowed.txt'), 'sandbox staging output was not returned');
    boundaryAssert(! is_file($source . '/should-not-write'), 'sandbox mutated the plugin package');
    boundaryAssert($exit === 0, 'sandbox plugin did not exit cleanly');

    echo "production plugin sandbox boundary passed\n";
} finally {
    fclose($networkServer);
    boundaryRemove($canary);
    boundaryRemove($root);
}
