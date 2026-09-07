<?php

declare(strict_types=1);

namespace App\System\Scheduler;

use App\Fixity\VerificationCandidateFinder;
use App\Jobs\JobDispatcher;
use App\System\Storage\VaultStorageAvailability;
use Tempest\DateTime\DateTime;

final readonly class VerificationScheduler
{
    private const int DISPATCH_LIMIT = 100;

    public function __construct(
        private VerificationCandidateFinder $candidates,
        private JobDispatcher $dispatch,
        private VaultStorageAvailability $storage,
    ) {}

    public function run(?DateTime $now = null): VerificationScheduleResult
    {
        if ($this->storage->isUnavailable()) {
            return new VerificationScheduleResult(0, 0, 0, 0, true, false);
        }

        $plan = $this->candidates->find($now);
        $dispatchable = array_slice($plan->assets, 0, self::DISPATCH_LIMIT);
        $dispatched = 0;

        foreach ($dispatchable as $asset) {
            if ($this->storage->isUnavailable()) {
                return new VerificationScheduleResult(
                    eligible: $plan->eligible,
                    alreadyQueued: $plan->alreadyQueued,
                    dispatched: $dispatched,
                    unverifiable: $plan->unverifiable,
                    skippedStorageUnavailable: true,
                    limitReached: false,
                );
            }

            $this->dispatch->dispatch(
                type: 'core.verify_vault',
                entityType: 'asset',
                entityId: (string) $asset->id,
                payload: ['asset_id' => (string) $asset->id],
                workload: 'background',
            );
            $dispatched++;
        }

        return new VerificationScheduleResult(
            eligible: $plan->eligible,
            alreadyQueued: $plan->alreadyQueued,
            dispatched: $dispatched,
            unverifiable: $plan->unverifiable,
            skippedStorageUnavailable: false,
            limitReached: count($plan->assets) > self::DISPATCH_LIMIT,
        );
    }
}
