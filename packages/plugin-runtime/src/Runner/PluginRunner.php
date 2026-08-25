<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Runner;

use RuntimeException;
use Stashd\PluginRuntime\Package\PackageManager;
use Stashd\PluginRuntime\Sandbox\SandboxPolicy;
use Tempest\Support\Filesystem;

final readonly class PluginRunner
{
    public function __construct(private PackageManager $packages, private SandboxPolicy $policy = new SandboxPolicy(), private ?string $sdkRoot = null) {}

    public function start(string $pluginId, string $stagingRoot): PluginProcess
    {
        $package = $this->packages->activePath($pluginId);

        if ($package === null) {
            throw new RuntimeException('plugin is not active: ' . $pluginId);
        }
        $manifestPath = Filesystem\is_file($package . '/plugin.json') ? $package . '/plugin.json' : $package . '/stashd-plugin/plugin.json';
        $manifest = json_decode(Filesystem\read_file($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($manifest)) {
            throw new RuntimeException('active plugin manifest is invalid');
        }
        $entrypoint = is_string($manifest['entrypoint'] ?? null) ? $manifest['entrypoint'] : 'stashd-plugin/plugin.php';

        $bundledSdk = $package . '/vendor/stashd/php-sdk';
        $sdkRoot = $this->sdkRoot ?? (Filesystem\is_directory($bundledSdk) ? $bundledSdk : dirname(__DIR__, 4) . '/vendor/stashd/php-sdk');

        return new PluginProcess($package, $stagingRoot, $entrypoint, $this->policy, $sdkRoot);
    }
}
