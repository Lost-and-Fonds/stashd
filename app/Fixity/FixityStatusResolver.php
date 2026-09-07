<?php

declare(strict_types=1);

namespace App\Fixity;

use App\Jobs\JobRepository;
use App\Jobs\JobType;
use App\Vault\AssetId;
use App\Vault\AssetRecord;
use App\Vault\AssetState;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class FixityStatusResolver
{
    public function __construct(
        private PreservationEventRepository $events,
        private JobRepository $jobs,
        private VerificationPolicy $policy,
    ) {}

    public function forAsset(AssetRecord $asset, ?DateTime $now = null): FixityStatus
    {
        $event = $this->events->latestForAsset(AssetId::fromPrimaryKey($asset->id), PreservationEventType::FixityCheck);

        return $this->resolve(
            $asset,
            $event,
            $this->jobs->hasPendingOrProcessingEntity(JobType::core('core.verify_vault'), 'asset', (string) $asset->id),
            $now ?? DateTime::now(Timezone::UTC),
        );
    }

    /** @param list<AssetRecord> $assets
     * @param array<string, true>|null $verifyingIds
     * @return array<string, FixityStatus>
     */
    public function forAssets(array $assets, ?DateTime $now = null, ?array $verifyingIds = null): array
    {
        $assetIds = array_map(static fn(AssetRecord $asset): AssetId => AssetId::fromPrimaryKey($asset->id), $assets);
        $events = $this->events->latestForAssets($assetIds, PreservationEventType::FixityCheck);
        $verifying = $verifyingIds ?? $this->jobs->pendingOrProcessingEntityIds(JobType::core('core.verify_vault'), 'asset');
        $now ??= DateTime::now(Timezone::UTC);
        $statuses = [];

        foreach ($assets as $asset) {
            $id = (string) $asset->id;
            $statuses[$id] = $this->resolve($asset, $events[$id] ?? null, isset($verifying[$id]), $now);
        }

        return $statuses;
    }

    public function verificationDueAt(AssetRecord $asset): ?DateTime
    {
        if ($asset->checksum === null || $asset->checksum === '' || $asset->lastVerifiedAt === null) {
            return null;
        }

        return $this->policy->dueAt($asset->lastVerifiedAt);
    }

    private function resolve(AssetRecord $asset, ?PreservationEventRecord $event, bool $verifying, DateTime $now): FixityStatus
    {
        if ($verifying) {
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

        $status = match ([$event?->eventType, $event?->outcome]) {
            [PreservationEventType::FixityCheck, PreservationOutcome::Success] => FixityStatus::Verified,
            [PreservationEventType::FixityCheck, PreservationOutcome::Mismatch] => FixityStatus::Mismatch,
            [PreservationEventType::FixityCheck, PreservationOutcome::Missing] => FixityStatus::Missing,
            default => FixityStatus::Unverified,
        };

        if ($status === FixityStatus::Verified && $asset->lastVerifiedAt !== null && $this->policy->dueAt($asset->lastVerifiedAt)->beforeOrAtTheSameTime($now)) {
            return FixityStatus::Due;
        }

        return $status;
    }
}
