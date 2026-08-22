<?php

declare(strict_types=1);

namespace App\System\Wiring;

use App\Jobs\Handlers\AddInputJobHandler;
use App\Jobs\Handlers\BootJobHandler;
use App\Jobs\Handlers\BroadcastJobHandler;
use App\Jobs\Handlers\DownloadCaptionsJobHandler;
use App\Jobs\Handlers\DownloadJobHandler;
use App\Jobs\Handlers\PreflightJobHandler;
use App\Jobs\Handlers\RetryFailedDownloadsJobHandler;
use App\Jobs\Handlers\StorageCheckJobHandler;
use App\Jobs\Handlers\SyncInputJobHandler;
use App\Jobs\Handlers\VerifyVaultJobHandler;
use App\Jobs\JobHandlerRegistry;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

final class JobHandlerRegistryInitializer implements Initializer
{
    public function initialize(Container $container): JobHandlerRegistry
    {
        return new JobHandlerRegistry([
            $container->get(BootJobHandler::class),
            $container->get(PreflightJobHandler::class),
            $container->get(AddInputJobHandler::class),
            $container->get(SyncInputJobHandler::class),
            $container->get(RetryFailedDownloadsJobHandler::class),
            $container->get(DownloadJobHandler::class),
            $container->get(DownloadCaptionsJobHandler::class),
            $container->get(StorageCheckJobHandler::class),
            $container->get(VerifyVaultJobHandler::class),
            $container->get(BroadcastJobHandler::class),
        ]);
    }
}
