<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Http\Api\ApiJson;
use App\Jobs\JobHandler;
use App\Jobs\JobProgressReporter;
use App\Jobs\JobProgressUpdate;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Stashes\CreateStashWithInitialInput;
use App\Stashes\StashId;
use App\Stashes\StashRepository;
use App\System\Activity\ActivityEventService;
use RuntimeException;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class AddInputJobHandler implements JobHandler
{
    public function __construct(
        private CreateStashWithInitialInput $stashFromPreflight,
        private StashRepository $stashes,
        private JobRepository $jobs,
        private ActivityEventService $activity,
    ) {}

    public function handle(JobRecord $job, JobProgressReporter $context): void
    {
        $context->progress($job, JobProgressUpdate::ofSteps(0, 1, 'Adding input to stash'));

        /** @var array<string, mixed> $payload */
        $payload = $job->payload ?? [];

        $stashId = StashId::parse(ApiJson::string($payload['stash_id'] ?? null));
        $stash = $this->stashes->find($stashId)
            ?? throw new RuntimeException('Add-input job targets a stash that no longer exists.');

        /** @var array<string, mixed> $options */
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
        $plugin = $payload['plugin'] ?? null;
        $source = $payload['source'] ?? null;

        if (is_string($plugin) && is_array($source)) {
            /** @var array<string, mixed> $source */
            $result = $this->stashFromPreflight->addToExisting($stash, $plugin, $source, $options, $context, $job);
        } else {
            throw new RuntimeException('Add-input job is missing its source.');
        }

        $job->progressCurrent = 1;
        $job->progressTotal = 1;
        $job->progressPercent = 100.0;
        $job->progressLabel = 'Input added to stash';
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);
        $context->progress($job, JobProgressUpdate::ofSteps(1, 1, $job->progressLabel));

        $this->activity->stashInputCommitted(null, $job, $result);
    }
}
