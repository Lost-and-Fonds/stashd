<?php

declare(strict_types=1);

namespace App\Fixity;

use App\Jobs\JobRepository;
use App\Jobs\JobType;
use App\Vault\AssetId;
use App\Vault\AssetRecord;
use App\Vault\AssetState;

final readonly class FixityStatusResolver
{
    public function __construct(
        private PreservationEventRepository $events,
        private JobRepository $jobs,
    ) {}

    public function forAsset(AssetRecord $asset): FixityStatus
    {
        if ($this->jobs->hasPendingOrProcessingEntity(JobType::core('core.verify_vault'), 'asset', (string) $asset->id)) {
            return FixityStatus::Verifying;
        }

        if ($asset->state === AssetState::Missing) {
            return FixityStatus::Missing;
        }

        if ($asset->checksum === null || $asset->checksum === '') {
            return FixityStatus::Unverified;
        }

        if ($asset->state === AssetState::Stale) {
            return FixityStatus::Mismatch;
        }

        $event = $this->events->latestForAsset(AssetId::fromPrimaryKey($asset->id));

        return match ([$event?->eventType, $event?->outcome]) {
            [PreservationEventType::FixityCheck, PreservationOutcome::Success] => FixityStatus::Verified,
            [PreservationEventType::FixityCheck, PreservationOutcome::Mismatch] => FixityStatus::Mismatch,
            [PreservationEventType::FixityCheck, PreservationOutcome::Missing] => FixityStatus::Missing,
            default => FixityStatus::Unverified,
        };
    }
}
