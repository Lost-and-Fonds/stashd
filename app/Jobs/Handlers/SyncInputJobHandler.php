<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Jobs\JobProgressReporter;
use App\Jobs\JobProgressUpdate;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Stashes\StashInputId;
use App\Stashes\StashInputRepository;
use App\Stashes\SyncStashInput;
use App\System\Activity\ActivityEventService;
use RuntimeException;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class SyncInputJobHandler implements JobHandler
{
    public function __construct(
        private SyncStashInput $sync,
        private StashInputRepository $stashInputs,
        private JobRepository $jobs,
        private ActivityEventService $activity,
    ) {}

    public function handle(JobRecord $job, JobProgressReporter $context): void
    {
        $context->progress($job, JobProgressUpdate::ofSteps(0, 1, 'Checking for new items'));

        $payload = $job->payload ?? [];
        $rawInputId = $payload['stash_input_id'] ?? null;

        if (! is_string($rawInputId) || ! StashInputId::isValid($rawInputId)) {
            throw new RuntimeException('Sync job is missing a valid stash_input_id.');
        }

        $input = $this->stashInputs->find(StashInputId::parse($rawInputId))
            ?? throw new RuntimeException('Sync job targets an input that no longer exists.');

        $result = $this->sync->execute($input);

        $job->progressCurrent = 1;
        $job->progressTotal = 1;
        $job->progressPercent = 100.0;
        $job->progressLabel = $result->stashItemsCreated > 0
            ? sprintf('Added %d new item(s)', $result->stashItemsCreated)
            : 'No new items';
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);
        $context->progress($job, JobProgressUpdate::ofSteps(1, 1, $job->progressLabel));

        $this->activity->stashInputSynced(null, $job, $input, $result);
    }
}
