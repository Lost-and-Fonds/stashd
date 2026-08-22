<?php

declare(strict_types=1);

namespace App\System\Scheduler;

use App\Commands\CommandDispatchService;
use App\Commands\CommandType;
use App\Stashes\StashInputRepository;
use App\Stashes\SyncMode;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class RoutineDiscoveryScheduler
{
    private const int CHECK_INTERVAL_SECONDS = 3600;

    public function __construct(
        private StashInputRepository $inputs,
        private CommandDispatchService $dispatch,
    ) {}

    public function runDueChecks(): int
    {
        $now = DateTime::now(Timezone::UTC);
        $scheduled = 0;

        foreach ($this->inputs->listDueForAutomaticSync($now) as $input) {
            $this->dispatch->dispatch(
                CommandType::StashSyncInput,
                ['stash_input_id' => (string) $input->id],
            );

            // Only the schedule moves here -- this is the dispatch debounce,
            // not the check itself. SyncStashInput records lastCheckedAt (and
            // the success/failure counters) when the work actually runs.
            $input->nextCheckAt = $now->plusSeconds(self::CHECK_INTERVAL_SECONDS);
            $input->syncMode = $input->syncMode ?? SyncMode::Automatic;
            $this->inputs->save($input);
            $scheduled++;
        }

        return $scheduled;
    }
}
