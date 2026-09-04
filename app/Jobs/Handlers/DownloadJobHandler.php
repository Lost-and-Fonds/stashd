<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Http\Api\ApiJson;
use App\Downloads\DownloadException;
use App\Downloads\DownloadMediaItem;
use App\Jobs\JobHandler;
use App\Jobs\JobProgressReporter;
use App\Jobs\JobProgressUpdate;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Stashes\StashId;
use App\Support\PrefixedUlid;
use App\System\Activity\ActivityEventService;
use App\Vault\MediaItemId;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Duration;
use Tempest\DateTime\Timezone;

final readonly class DownloadJobHandler implements JobHandler
{
    public function __construct(
        private DownloadMediaItem $executor,
        private JobRepository $jobs,
        private ActivityEventService $activity,
    ) {}

    public function handle(JobRecord $job, JobProgressReporter $context): void
    {
        $context->progress($job, JobProgressUpdate::ofPercent(0.0, 'Preparing download'));

        $payload = $job->payload ?? [];

        $mediaItemId = MediaItemId::parse(ApiJson::string($payload['media_item_id'] ?? null));
        $stashId = StashId::parse(ApiJson::string($payload['stash_id'] ?? null));
        $force = (bool) ($payload['force'] ?? false);

        try {
            $result = $this->executor->execute(
                mediaItemId: $mediaItemId,
                stashId: $stashId,
                jobId: PrefixedUlid::parse((string) $job->id),
                force: $force,
                onProgress: function (?string $stage = null, ?float $fraction = null) use ($context, $job): void {
                    if ($stage === null || $stage === '') {

                        return;
                    }

                    $context->progress($job, $fraction === null ? JobProgressUpdate::indeterminate($stage) : JobProgressUpdate::ofPercent($fraction * 100, $stage));
                },
            );

            $job->progressPercent = 100.0;
            $job->progressLabel = $result->skipped ? 'Download skipped (already in Vault)' : 'Download complete';
            $job->progressEtaSeconds = Duration::zero();
            $job->finishedAt = DateTime::now(Timezone::UTC);
            $this->jobs->save($job);
            $context->progress($job, JobProgressUpdate::ofPercent(100.0, $job->progressLabel, 0));

            $this->activity->downloadCompleted(null, $job, $result);
        } catch (DownloadException $exception) {
            throw $exception;
        }
    }

}
