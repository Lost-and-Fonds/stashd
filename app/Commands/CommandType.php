<?php

declare(strict_types=1);

namespace App\Commands;

enum CommandType: string
{
    case BroadcastPlan = 'broadcast.plan';
    case BroadcastRebuild = 'broadcast.rebuild';
    case BroadcastRebuildItem = 'broadcast.rebuild_item';
    case BroadcastVerify = 'broadcast.verify';
    case BroadcastPrune = 'broadcast.prune';
    case BroadcastDelete = 'broadcast.delete';
    case BroadcastTrigger = 'broadcast.trigger';
    case BroadcastRotateToken = 'broadcast.rotate_token';
    case StashPreflight = 'stash.preflight';
    case StashAddInput = 'stash.add_input';
    case StashSyncInput = 'stash.sync_input';
    case StashRetryFailed = 'stash.retry_failed';
    case SystemBoot = 'system.boot';
    case SystemStorageCheck = 'system.storage_check';
    case ItemDownload = 'item.download';
    case AssetVerify = 'asset.verify';
    case SystemVerifyVault = 'system.verify_vault';
    case MediaServerTestConnection = 'media_server.test_connection';
    case MediaServerListLibraries = 'media_server.list_libraries';
    case AssetDownloadCaptions = 'asset.download_captions';
}
