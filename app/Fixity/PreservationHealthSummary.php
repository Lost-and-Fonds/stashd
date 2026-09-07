<?php

declare(strict_types=1);

namespace App\Fixity;

final readonly class PreservationHealthSummary
{
    /** @param array<string, int> $fixityCounts
     * @param array<string, int> $healthCounts
     */
    public function __construct(
        public PreservationHealth $health,
        public array $fixityCounts,
        public array $healthCounts,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'preservationHealth' => $this->health->value,
            'assetFixityCounts' => $this->fixityCounts,
            'assetHealthCounts' => $this->healthCounts,
        ];
    }
}
