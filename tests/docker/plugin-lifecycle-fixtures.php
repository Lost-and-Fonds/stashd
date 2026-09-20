<?php

declare(strict_types=1);

use Stashd\PluginRuntime\Package\PluginBuilder;
use Stashd\PluginRuntime\Package\Umoci;

require '/var/www/html/vendor/autoload.php';

function lifecycleRemove(string $path): void
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
            lifecycleRemove($path . '/' . $entry);
        }
    }

    @rmdir($path);
}

$sourceRoot = getenv('STASHD_FIXTURE_REPO') ?: '/source';
$outputRoot = getenv('STASHD_FIXTURE_OUTPUT') ?: '/artifacts';
$fixture = $sourceRoot . '/packages/plugin-runtime/tests/fixtures/fixture-plugin.php';
$frameCodec = $sourceRoot . '/packages/plugin-runtime/tests/fixtures/FrameCodec.php';
$composer = sys_get_temp_dir() . '/fixture-composer-' . bin2hex(random_bytes(6));

if (! is_file($frameCodec)) {
    throw new RuntimeException('fixture frame codec is unavailable');
}

file_put_contents($composer, "#!/bin/sh\nset -eu\nmkdir -p \"\$COMPOSER_VENDOR_DIR\"\nprintf '%s\\n' '<?php' > \"\$COMPOSER_VENDOR_DIR/autoload.php\"\n");
chmod($composer, 0755);
putenv('COMPOSER_BINARY=' . $composer);

$artifacts = [
    'a' => ['version' => '1.0.0', 'label' => 'Lifecycle 1.0.0'],
    'conflict' => ['version' => '1.0.0', 'label' => 'Lifecycle conflict'],
    'c' => ['version' => '1.1.0', 'label' => 'Lifecycle 1.1.0'],
];
$identities = [];

try {
    lifecycleRemove($outputRoot);
    mkdir($outputRoot, 0700, true);

    foreach ($artifacts as $name => $artifact) {
        $source = sys_get_temp_dir() . '/lifecycle-source-' . $name . '-' . bin2hex(random_bytes(6));
        $layout = $outputRoot . '/' . $name . '/layout';
        mkdir($source . '/stashd-plugin', 0755, true);
        mkdir($source . '/rpc', 0755, true);
        $helperPath = $source . '/helper.bin';
        file_put_contents($helperPath, "#!/bin/sh\nexit 0\n");
        $helperHash = hash_file('sha256', $helperPath);
        file_put_contents($source . '/plugin.php', str_replace('__LABEL__', addslashes($artifact['label']), <<<'PLUGIN'
<?php
declare(strict_types=1);
require_once __DIR__ . '/rpc/FrameCodec.php';
FrameCodec::write(STDOUT, ['protocol' => 1, 'id' => 'hello', 'kind' => 'request', 'method' => 'hello', 'params' => ['min' => 1, 'max' => 1]]);
$hello = FrameCodec::read(STDIN, 5.0);
if (($hello['id'] ?? null) !== 'hello' || ($hello['result']['protocol'] ?? null) !== 1) {
    throw new RuntimeException('RPC handshake failed');
}
while (($message = FrameCodec::read(STDIN, 10.0)) !== null) {
    $id = $message['id'] ?? null;
    if (($message['method'] ?? null) !== 'broadcast.operation') {
        FrameCodec::write(STDOUT, ['protocol' => 1, 'id' => $id, 'kind' => 'response', 'error' => ['message' => 'unexpected method']]);
        continue;
    }
    FrameCodec::write(STDOUT, ['protocol' => 1, 'id' => $id, 'kind' => 'response', 'result' => ['choices' => [['value' => 'fixture', 'label' => '__LABEL__']]]]);
}
PLUGIN));
        copy($frameCodec, $source . '/rpc/FrameCodec.php');
        file_put_contents($source . '/stashd-plugin/plugin.json', json_encode([
            'id' => 'lifecycle-fixture',
            'name' => 'Lifecycle fixture',
            'version' => $artifact['version'],
            'runtime' => 'php',
            'api_version' => '0.1',
            'entrypoint' => 'plugin.php',
            'requires' => ['php' => '>=8.5', 'extensions' => []],
            'architectures' => ['amd64', 'arm64'],
            'helpers' => ['fixture' => ['executable' => 'helpers/fixture-helper']],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($source . '/stashd-plugin/helpers.lock.json', json_encode([
            'helpers' => ['fixture' => ['platforms' => ['linux-amd64' => ['url' => $helperPath, 'sha256' => $helperHash]]]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($source . '/composer.json', '{"name":"stashd/lifecycle-fixture","require":{"php":">=8.5"}}');
        file_put_contents($source . '/composer.lock', '{}');

        $built = (new PluginBuilder($outputRoot . '/' . $name . '/builds', new Umoci()))->materialize($source, 'linux-amd64');
        rename($built['layout'], $layout);
        $identities[$name] = ['version' => $artifact['version'], 'label' => $artifact['label'], 'digest' => $built['digest']];
        lifecycleRemove($source);
    }

    file_put_contents($outputRoot . '/identities.json', json_encode($identities, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} finally {
    lifecycleRemove($composer);
}
