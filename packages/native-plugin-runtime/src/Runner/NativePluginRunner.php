<?php

declare(strict_types=1);

namespace Stashd\NativeRuntime\Runner;

use RuntimeException;
use Stashd\NativeRuntime\Package\PackageManager;
use Stashd\NativeRuntime\Sandbox\SandboxPolicy;

final readonly class NativePluginRunner
{
    public function __construct(private PackageManager $packages, private SandboxPolicy $policy = new SandboxPolicy())
    {
    }

    public function start(string $pluginId, string $stagingRoot): NativePluginProcess
    {
        $package = $this->packages->activePath($pluginId);
        if ($package === null) {
            throw new RuntimeException('plugin is not active: ' . $pluginId);
        }
        $manifest = json_decode((string) file_get_contents($package . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new RuntimeException('active plugin manifest is invalid');
        }
        $entrypoint = is_string($manifest['entrypoint'] ?? null) ? $manifest['entrypoint'] : 'plugin.php';
        return new NativePluginProcess($package, $stagingRoot, $entrypoint, $this->policy);
    }
}
