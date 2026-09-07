<?php

declare(strict_types=1);

namespace App\Fixity;

use App\Vault\AssetRecord;

final readonly class VerificationCandidatePlan
{
    /** @param list<AssetRecord> $assets */
    public function __construct(
        public array $assets,
        public int $eligible,
        public int $alreadyQueued,
    ) {}
}
