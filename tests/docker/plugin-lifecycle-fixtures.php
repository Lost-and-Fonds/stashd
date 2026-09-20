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
$sdkRoot = '/var/www/html/vendor/stashd/php-sdk/src';
$composer = sys_get_temp_dir() . '/fixture-composer-' . bin2hex(random_bytes(6));

if (! is_file($fixture) || ! is_dir($sdkRoot)) {
    throw new RuntimeException('fixture source or bundled SDK is unavailable');
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
        mkdir($source . '/sdk', 0755, true);
        mkdir($source . '/rpc', 0755, true);
        file_put_contents($source . '/plugin.php', str_replace('Fixture choice', $artifact['label'], file_get_contents($fixture)));
        copy($frameCodec, $source . '/rpc/FrameCodec.php');
        foreach (glob($sdkRoot . '/*.php') ?: [] as $sdkFile) {
            copy($sdkFile, $source . '/sdk/' . basename($sdkFile));
        }
        file_put_contents($source . '/stashd-plugin/plugin.json', json_encode([
            'id' => 'lifecycle-fixture',
            'name' => 'Lifecycle fixture',
            'version' => $artifact['version'],
            'runtime' => 'php',
            'api_version' => '0.1',
            'entrypoint' => 'plugin.php',
            'requires' => ['php' => '>=8.5', 'extensions' => []],
            'architectures' => ['amd64', 'arm64'],
            'helpers' => [],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($source . '/stashd-plugin/helpers.lock.json', '{"helpers":[]}');
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
