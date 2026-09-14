<?php

declare(strict_types=1);

namespace App\System\Scheduler;

use App\Jobs\JobDispatcher;
use App\Jobs\JobType;
use App\Jobs\JobRepository;
use App\Stashes\StashInputRepository;
use App\Support\PrefixedUlid;
use App\Stashes\SyncMode;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class RoutineDiscoveryScheduler
{
    private const int REFRESH_INTERVAL_SECONDS = 900;
    private const int COMPLETE_INTERVAL_SECONDS = 86400;
    private const int REFRESH_JITTER_SECONDS = 120;

    public function __construct(
        private StashInputRepository $inputs,
        private JobDispatcher $dispatch,
        private JobRepository $jobs,
    ) {}

    public function runDueChecks(): int
    {
        $now = DateTime::now(Timezone::UTC);
        $scheduled = 0;

        foreach ($this->inputs->listDueForAutomaticSync($now) as $input) {
            $inputId = (string) $input->id;

            if ($this->jobs->pendingOrProcessing(JobType::core('core.sync_input'), PrefixedUlid::parse($inputId)) === null) {
                $complete = $input->nextCompleteCheckAt === null || $input->nextCompleteCheckAt <= $now;
                $this->dispatch->dispatch(
                    type: 'core.sync_input',
                    entityType: 'stash_input',
                    entityId: $inputId,
                    stashId: (string) $input->stashId,
                    payload: ['stash_input_id' => $inputId, 'discovery_intent' => $complete ? 'complete' : 'refresh'],
                    workload: 'background',
                );
                $scheduled++;

                if ($complete) {
                    $input->nextCompleteCheckAt = $now->plusSeconds(self::COMPLETE_INTERVAL_SECONDS);
                }
            }

            // Only the schedule moves here -- this is the dispatch debounce,
            // not the check itself. SyncStashInput records lastCheckedAt (and
            // the success/failure counters) when the work actually runs.
            $input->nextCheckAt = $now->plusSeconds(self::REFRESH_INTERVAL_SECONDS + random_int(-self::REFRESH_JITTER_SECONDS, self::REFRESH_JITTER_SECONDS));
            $input->syncMode = $input->syncMode ?? SyncMode::Automatic;
            $this->inputs->save($input);
        }

        return $scheduled;
    }
}
