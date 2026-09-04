<?php

declare(strict_types=1);

namespace App\System\Wiring;

use App\Jobs\JobDefinition;
use App\Jobs\JobDefinitionRegistry;
use App\Jobs\JobType;
use App\Jobs\JobMessageHandler;
use App\Jobs\Handlers\AddInputJobHandler;
use App\Jobs\Handlers\BroadcastJobHandler;
use App\Jobs\Handlers\DownloadCaptionsJobHandler;
use App\Jobs\Handlers\DownloadJobHandler;
use App\Jobs\Handlers\ExternalBroadcastOperationJobHandler;
use App\Jobs\Handlers\StorageCheckJobHandler;
use App\Jobs\Handlers\SyncInputJobHandler;
use App\Jobs\Handlers\VerifyVaultJobHandler;
use App\Plugins\ExternalBroadcastPluginRegistry;
use App\Plugins\ExternalInputPluginRegistry;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

final class JobDefinitionRegistryInitializer implements Initializer
{
    public function initialize(Container $container): JobDefinitionRegistry
    {
        $definitions = [];

        $core = [
            'core.add_input' => [AddInputJobHandler::class, 'interactive'],
            'core.sync_input' => [SyncInputJobHandler::class, 'background'],
            'core.download' => [DownloadJobHandler::class, 'background'],
            'core.download_captions' => [DownloadCaptionsJobHandler::class, 'background'],
            'core.storage_check' => [StorageCheckJobHandler::class, 'background'],
            'core.verify_vault' => [VerifyVaultJobHandler::class, 'background'],
            'core.broadcast' => [BroadcastJobHandler::class, 'background'],
        ];

        foreach ($core as $type => [$handler, $workload]) {
            $definitions[] = new JobDefinition(
                type: new JobType($type),
                handler: $handler,
                workload: $workload,
            );
        }

        foreach ($container->get(ExternalInputPluginRegistry::class)->definitions() as $plugin) {
            $definitions = [...$definitions, ...$plugin->jobs];
        }

        foreach ($container->get(ExternalBroadcastPluginRegistry::class)->all() as $plugin) {
            $definitions = [...$definitions, ...$plugin->jobs];
        }

        return new JobDefinitionRegistry($definitions);
    }
}
