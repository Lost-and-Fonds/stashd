<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Runner;

use RuntimeException;
use Stashd\PluginRuntime\Package\PackageManager;
use Stashd\PluginRuntime\Sandbox\SandboxPolicy;

final readonly class PluginRunner
{
    public function __construct(private PackageManager $packages, private SandboxPolicy $policy = new SandboxPolicy(), private ?string $sdkRoot = null) {}

    public function start(string $pluginId, string $stagingRoot): PluginProcess
    {
        $package = $this->packages->activePath($pluginId);
        if ($package === null) {
            throw new RuntimeException('plugin is not active: ' . $pluginId);
        }
        $manifestPath = is_file($package . '/plugin.json') ? $package . '/plugin.json' : $package . '/stashd-plugin/plugin.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest)) {
            throw new RuntimeException('active plugin manifest is invalid');
        }
        $entrypoint = is_string($manifest['entrypoint'] ?? null) ? $manifest['entrypoint'] : 'stashd-plugin/plugin.php';

        $sdkRoot = $this->sdkRoot ?? dirname(__DIR__, 4) . '/vendor/stashd/plugin-sdk';

        return new PluginProcess($package, $stagingRoot, $entrypoint, $this->policy, $sdkRoot);
    }
}
