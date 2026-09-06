<?php

declare(strict_types=1);

namespace App\Fixity;

final readonly class ChecksumComparison
{
    public function __construct(
        public ChecksumComparisonOutcome $outcome,
        public ?string $expectedChecksum,
        public ?string $observedChecksum,
    ) {}
}
