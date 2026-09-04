<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Broadcasts\BroadcastItemRepository;
use App\Downloads\DownloadCaptions;
use App\Jobs\JobHandler;
use App\Jobs\JobProgressReporter;
use App\Jobs\JobProgressUpdate;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Jobs\JobDispatcher;
use App\Support\PrefixedUlid;
use App\Vault\MediaItemId;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class DownloadCaptionsJobHandler implements JobHandler
{
    public function __construct(private DownloadCaptions $captions, private JobRepository $jobs, private BroadcastItemRepository $broadcastItems, private JobDispatcher $dispatch) {}

    public function handle(JobRecord $job, JobProgressReporter $context): void
    {
        $payload = $job->payload ?? [];
        $mediaItemId = is_string($payload['media_item_id'] ?? null) ? $payload['media_item_id'] : '';
        $languages = is_string($payload['languages'] ?? null) ? $payload['languages'] : 'en';
        $this->captions->execute(MediaItemId::parse($mediaItemId), PrefixedUlid::parse((string) $job->id), $languages, ($payload['include_auto'] ?? false) === true);

        $job->progressCurrent = 1;
        $job->progressTotal = 1;
        $job->progressPercent = 100.0;
        $job->progressLabel = 'Captions downloaded';
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);
        $context->progress($job, JobProgressUpdate::ofSteps(1, 1, $job->progressLabel));


        foreach ($this->broadcastItems->listForMediaItem(MediaItemId::parse($mediaItemId)) as $item) {
            $this->dispatch->dispatch(
                'core.broadcast',
                entityType: 'broadcast',
                entityId: (string) $item->broadcast->id,
                payload: ['broadcast_id' => (string) $item->broadcast->id, 'action' => 'rebuild'],
                workload: 'background',
            );
        }
    }
}
