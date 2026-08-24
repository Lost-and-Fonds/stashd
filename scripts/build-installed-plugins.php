<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Stashd\PluginRuntime\Package\PackageManager;
use Stashd\PluginRuntime\Package\PluginBuilder;
use Stashd\PluginRuntime\Package\Umoci;

$root = getenv('STASHD_PLUGIN_PACKAGE_ROOT');
$root = is_string($root) && trim($root) !== '' ? trim($root) : dirname(__DIR__) . '/.stashd/plugin-packages';
$sources = array_values(array_filter([
    getenv('STASHD_JELLYFIN_ROOT') ?: dirname(__DIR__, 2) . '/plugins/jellyfin',
    getenv('STASHD_PLEX_ROOT') ?: dirname(__DIR__, 2) . '/plugins/plex',
    getenv('STASHD_PODCAST_ROOT') ?: dirname(__DIR__, 2) . '/plugins/podcast',
    getenv('STASHD_YOUTUBE_ROOT') ?: dirname(__DIR__, 2) . '/plugins/youtube',
], static fn(mixed $path): bool => is_string($path) && is_dir($path)));

if (count($sources) !== 4) {
    throw new RuntimeException('Jellyfin, Plex, Podcast, and YouTube source trees are required.');
}

$umoci = new Umoci();
$builder = new PluginBuilder($root . '/builds', $umoci);
$manager = new PackageManager($root, '0.1', null, $umoci);
$platform = match (php_uname('m')) {
    'x86_64', 'amd64' => 'linux-amd64',
    'aarch64', 'arm64' => 'linux-arm64',
    default => throw new RuntimeException('unsupported host architecture'),
};

foreach ($sources as $source) {
    $built = $builder->materialize($source, $platform);
    $manifest = $manager->installOciLayout($built['layout'], $built['digest']);
    $manager->activate($manifest->id, $manifest->version);
    $active = $manager->activePath($manifest->id);

    if ($active === null) {
        throw new RuntimeException('installed package is not active: ' . $manifest->id);
    }
    $declaration = json_decode((string) file_get_contents($active . '/stashd-plugin/plugin.json'), true, 512, JSON_THROW_ON_ERROR);

    foreach (is_array($declaration['helpers'] ?? null) ? $declaration['helpers'] : [] as $helper) {
        $executable = is_array($helper) && is_string($helper['executable'] ?? null) ? $active . '/' . $helper['executable'] : '';

        if ($executable === '' || ! is_executable($executable)) {
            throw new RuntimeException('installed helper is not executable: ' . $manifest->id);
        }
    }
    fwrite(STDOUT, $manifest->id . ' ' . $manifest->version . ' ' . $built['digest'] . PHP_EOL);
}
