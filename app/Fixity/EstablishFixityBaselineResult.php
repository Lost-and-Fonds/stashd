<?php

declare(strict_types=1);

namespace App\Fixity;

final readonly class EstablishFixityBaselineResult
{
    public function __construct(
        public EstablishFixityBaselineOutcome $outcome,
        public ?string $expectedChecksum = null,
        public ?string $observedChecksum = null,
    ) {}
}
