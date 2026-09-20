<?php

declare(strict_types=1);

use Stashd\PluginRuntime\Package\PackageManager;
use Stashd\PluginRuntime\Runner\PluginRunner;

require '/var/www/html/vendor/autoload.php';

function lifecycleRunRemove(string $path): void
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
            lifecycleRunRemove($path . '/' . $entry);
        }
    }

    @rmdir($path);
}

$expected = getenv('STASHD_LIFECYCLE_EXPECTED') ?: throw new RuntimeException('expected fixture result is required');
$staging = '/data/plugin-lifecycle-stage';
lifecycleRunRemove($staging);
mkdir($staging, 0700, true);
mkdir('/tmp/plugin-sdk', 0755, true);
$process = null;

try {
    $packages = new PackageManager('/data/plugins', ['0.2', '0.1'], 'amd64');
    $process = (new PluginRunner($packages, sdkRoot: '/tmp/plugin-sdk'))->start('lifecycle-fixture', $staging);
    $result = $process->invoke('broadcast.operation', ['name' => 'lifecycle'], static fn (array $message): array => throw new RuntimeException('unexpected capability request: ' . ($message['method'] ?? 'unknown')));
    $label = $result['choices'][0]['label'] ?? null;

    if ($label !== $expected) {
        throw new RuntimeException('active fixture returned ' . var_export($label, true) . ', expected ' . $expected);
    }

    $exit = $process->close();
    $process = null;

    if ($exit !== 0) {
        throw new RuntimeException('fixture exited with status ' . $exit);
    }

    fwrite(STDOUT, "plugin lifecycle runtime passed: $expected\n");
} finally {
    if ($process !== null) {
        $stderr = $process->stderr();
        $process->terminate();
        if ($stderr !== '') {
            fwrite(STDERR, $stderr);
        }
    }
    lifecycleRunRemove($staging);
}
