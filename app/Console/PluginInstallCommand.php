<?php

declare(strict_types=1);

namespace App\Console;

use App\Config\StashdConfig;
use Stashd\PluginRuntime\Package\OciRegistry;
use Stashd\PluginRuntime\Package\PackageManager;
use Tempest\Console\ConsoleArgument;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Tempest\Console\HasConsole;

final readonly class PluginInstallCommand
{
    use HasConsole;

    public function __construct(private StashdConfig $config) {}

    #[ConsoleCommand(name: 'stashd:plugin-install', description: 'Pull, validate, and activate a Stashd OCI plugin.')]
    public function __invoke(#[ConsoleArgument(description: 'OCI reference, for example ghcr.io/lost-and-fonds/jellyfin:1.0.0')] string $reference): ExitCode
    {
        $root = rtrim($this->config->dataPath, '/') . '/plugins';
        $layout = $root . '/staging/pull-' . bin2hex(random_bytes(8));
        $packages = new PackageManager($root);

        try {
            $digest = (new OciRegistry())->pull($reference, $layout, 'linux-' . match (php_uname('m')) {
                'x86_64', 'amd64' => 'amd64', 'aarch64', 'arm64' => 'arm64', default => throw new \RuntimeException('unsupported host architecture'),
            });
            $manifest = $packages->installOciLayout($layout, $digest, $reference);
            $packages->activate($manifest->id, $manifest->version);
        } catch (\Throwable $exception) {
            $this->console->error($exception->getMessage());

            return ExitCode::ERROR;
        } finally {
            $this->delete($layout);
        }

        $this->console->success("Installed {$manifest->id} {$manifest->version} ({$digest}).");

        return ExitCode::SUCCESS;
    }

    private function delete(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $child = $path . '/' . $entry;
                is_dir($child) && ! is_link($child) ? $this->delete($child) : unlink($child);
            }
        }
        rmdir($path);
    }
}
