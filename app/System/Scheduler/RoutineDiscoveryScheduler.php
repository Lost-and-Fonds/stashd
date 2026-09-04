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
    private const int CHECK_INTERVAL_SECONDS = 3600;

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
                $this->dispatch->dispatch(
                    type: 'core.sync_input',
                    entityType: 'stash_input',
                    entityId: $inputId,
                    stashId: (string) $input->stashId,
                    payload: ['stash_input_id' => $inputId],
                    workload: 'background',
                );
                $scheduled++;
            }

            // Only the schedule moves here -- this is the dispatch debounce,
            // not the check itself. SyncStashInput records lastCheckedAt (and
            // the success/failure counters) when the work actually runs.
            $input->nextCheckAt = $now->plusSeconds(self::CHECK_INTERVAL_SECONDS);
            $input->syncMode = $input->syncMode ?? SyncMode::Automatic;
            $this->inputs->save($input);
        }

        return $scheduled;
    }
}
