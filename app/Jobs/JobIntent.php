<?php

declare(strict_types=1);

namespace App\Jobs;

enum JobIntent: string
{
    case InitialBackfill = 'initial_backfill';
    case Download = 'download';
    // No handler is registered for Enrich (see JobHandlerRegistryInitializer)
    // -- kept deliberately unwired as the fixture Phase2ExecutionTest uses to
    // prove the worker fails a job cleanly when no handler exists.
    case Enrich = 'enrich';
    case Broadcast = 'broadcast';
    case StorageCheck = 'storage_check';
    case SponsorBlockRefresh = 'sponsorblock_refresh';
    case Preflight = 'preflight';
    case AddInput = 'add_input';
    case SyncInput = 'sync_input';
    case RetryFailedDownloads = 'retry_failed_downloads';
    case Boot = 'boot';
    case VerifyVault = 'verify_vault';
    case MediaServer = 'media_server';
    case TranscodePodcastAudio = 'transcode_podcast_audio';
    case DownloadCaptions = 'download_captions';

    public function lane(): JobLane
    {
        return match ($this) {
            self::Download, self::Broadcast, self::TranscodePodcastAudio, self::DownloadCaptions, self::SponsorBlockRefresh => JobLane::Bulk,
            self::InitialBackfill, self::SyncInput => JobLane::Discovery,
            default => JobLane::Interactive,
        };
    }
}
