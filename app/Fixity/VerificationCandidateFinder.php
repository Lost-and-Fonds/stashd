<?php

declare(strict_types=1);

namespace App\Fixity;

use App\Jobs\JobRepository;
use App\Jobs\JobType;
use App\Vault\AssetRecord;
use App\Vault\AssetRepository;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class VerificationCandidateFinder
{
    public function __construct(
        private AssetRepository $assets,
        private FixityStatusResolver $fixity,
        private JobRepository $jobs,
    ) {}

    public function find(?DateTime $now = null): VerificationCandidatePlan
    {
        $assets = $this->assets->listPreservedForHealth();

        if ($assets === []) {
            return new VerificationCandidatePlan([], 0, 0);
        }

        $verifyingIds = $this->jobs->pendingOrProcessingEntityIds(JobType::core('core.verify_vault'), 'asset');
        $statuses = $this->fixity->forAssets($assets, $now ?? DateTime::now(Timezone::UTC), $verifyingIds);
        $candidates = [];
        $eligible = 0;
        $alreadyQueued = 0;

        foreach ($assets as $asset) {
            $status = $statuses[(string) $asset->id] ?? FixityStatus::Unverified;

            if ($status === FixityStatus::Verifying) {
                $alreadyQueued++;
                continue;
            }

            if (in_array($status, [FixityStatus::Unverified, FixityStatus::Due], true)) {
                $eligible++;
                $candidates[] = $asset;
            }
        }

        usort($candidates, function (AssetRecord $left, AssetRecord $right) use ($statuses): int {
            $leftStatus = $statuses[(string) $left->id] ?? FixityStatus::Unverified;
            $rightStatus = $statuses[(string) $right->id] ?? FixityStatus::Unverified;
            $statusComparison = self::statusRank($leftStatus) <=> self::statusRank($rightStatus);

            if ($statusComparison !== 0) {
                return $statusComparison;
            }

            $leftDate = $leftStatus === FixityStatus::Due ? $this->fixity->verificationDueAt($left) : $left->createdAt;
            $rightDate = $rightStatus === FixityStatus::Due ? $this->fixity->verificationDueAt($right) : $right->createdAt;
            $dateComparison = self::compareDates($leftDate, $rightDate);

            return $dateComparison !== 0 ? $dateComparison : strcmp((string) $left->id, (string) $right->id);
        });

        return new VerificationCandidatePlan($candidates, $eligible, $alreadyQueued);
    }

    private static function compareDates(?DateTime $left, ?DateTime $right): int
    {
        if ($left === null && $right === null) {
            return 0;
        }

        if ($left === null) {
            return -1;
        }

        if ($right === null) {
            return 1;
        }

        if ($left->before($right)) {
            return -1;
        }

        return $left->after($right) ? 1 : 0;
    }

    private static function statusRank(FixityStatus $status): int
    {
        return match ($status) {
            FixityStatus::Unverified => 0,
            FixityStatus::Due => 1,
            default => 2,
        };
    }
}
