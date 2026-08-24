<?php

declare(strict_types=1);

namespace App\Console;

use App\Config\StashdConfig;
use Stashd\PluginRuntime\Package\PackageManager;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Tempest\Console\HasConsole;

final readonly class PluginListCommand
{
    use HasConsole;

    public function __construct(private StashdConfig $config) {}

    #[ConsoleCommand(name: 'stashd:plugin-list', description: 'List active Stashd OCI plugins.')]
    public function __invoke(): ExitCode
    {
        $plugins = (new PackageManager(rtrim($this->config->dataPath, '/') . '/plugins'))->installed();

        if ($plugins === []) {
            $this->console->info('No OCI plugins are installed.');

            return ExitCode::SUCCESS;
        }

        foreach ($plugins as $plugin) {
            $this->console->keyValue($plugin['id'], "{$plugin['version']} {$plugin['runtime']} {$plugin['digest']} {$plugin['reference']}");
        }

        return ExitCode::SUCCESS;
    }
}
