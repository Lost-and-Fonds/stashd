<?php

declare(strict_types=1);

namespace App\System\Wiring;

use App\Broadcasts\BroadcastCommandHandler;
use App\Broadcasts\BroadcastItemRepository;
use App\Broadcasts\BroadcastRepository;
use App\Commands\CommandHandlerRegistry;
use App\Commands\CommandRepository;
use App\Commands\CommandType;
use App\Downloads\AssetDownloadCaptionsCommandHandler;
use App\Downloads\ItemDownloadCommandHandler;
use App\Jobs\JobRepository;
use App\MediaServers\MediaServerCommandHandler;
use App\MediaServers\MediaServerConnectionRepository;
use App\Stashes\StashAddInputCommandHandler;
use App\Stashes\StashPreflightCommandHandler;
use App\Stashes\StashRetryFailedCommandHandler;
use App\Stashes\StashSyncInputCommandHandler;
use App\System\SystemStorageCheckCommandHandler;
use App\Transcoding\AssetTranscodePodcastAudioCommandHandler;
use App\Vault\AssetVerifyCommandHandler;
use App\Vault\SystemVerifyVaultCommandHandler;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

final class CommandHandlerRegistryInitializer implements Initializer
{
    public function initialize(Container $container): CommandHandlerRegistry
    {
        $commands = $container->get(CommandRepository::class);
        $jobs = $container->get(JobRepository::class);
        $broadcasts = $container->get(BroadcastRepository::class);
        $broadcastItems = $container->get(BroadcastItemRepository::class);
        $connections = $container->get(MediaServerConnectionRepository::class);

        return new CommandHandlerRegistry([
            $container->get(StashPreflightCommandHandler::class),
            $container->get(StashAddInputCommandHandler::class),
            $container->get(StashSyncInputCommandHandler::class),
            $container->get(StashRetryFailedCommandHandler::class),
            $container->get(ItemDownloadCommandHandler::class),
            $container->get(AssetDownloadCaptionsCommandHandler::class),
            $container->get(SystemStorageCheckCommandHandler::class),
            $container->get(SystemVerifyVaultCommandHandler::class),
            $container->get(AssetVerifyCommandHandler::class),
            $container->get(AssetTranscodePodcastAudioCommandHandler::class),
            new BroadcastCommandHandler($commands, $jobs, $broadcasts, $broadcastItems, CommandType::BroadcastPlan),
            new BroadcastCommandHandler($commands, $jobs, $broadcasts, $broadcastItems, CommandType::BroadcastRebuild),
            new BroadcastCommandHandler($commands, $jobs, $broadcasts, $broadcastItems, CommandType::BroadcastRebuildItem),
            new BroadcastCommandHandler($commands, $jobs, $broadcasts, $broadcastItems, CommandType::BroadcastVerify),
            new BroadcastCommandHandler($commands, $jobs, $broadcasts, $broadcastItems, CommandType::BroadcastPrune),
            new BroadcastCommandHandler($commands, $jobs, $broadcasts, $broadcastItems, CommandType::BroadcastDelete),
            new BroadcastCommandHandler($commands, $jobs, $broadcasts, $broadcastItems, CommandType::BroadcastTrigger),
            new BroadcastCommandHandler($commands, $jobs, $broadcasts, $broadcastItems, CommandType::BroadcastRotateToken),
            new MediaServerCommandHandler($commands, $jobs, $connections, CommandType::MediaServerTestConnection),
            new MediaServerCommandHandler($commands, $jobs, $connections, CommandType::MediaServerListLibraries),
        ]);
    }
}
