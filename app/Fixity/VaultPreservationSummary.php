<?php

declare(strict_types=1);

namespace App\Fixity;

use Tempest\DateTime\DateTime;

final readonly class VaultPreservationSummary
{
    /** @param array<string, int> $fixityCounts
     * @param array<string, int> $healthCounts
     */
    public function __construct(
        public PreservationHealth $health,
        public array $fixityCounts,
        public array $healthCounts,
        public int $totalPreservedAssets,
        public int $verifiableAssets,
        public ?DateTime $oldestSuccessfulVerificationAt,
        public int $criticalItems,
        public int $attentionItems,
        public bool $storageUnavailable,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'health' => $this->health->value,
            'fixity_counts' => $this->fixityCounts,
            'health_counts' => $this->healthCounts,
            'total_preserved_assets' => $this->totalPreservedAssets,
            'verifiable_assets' => $this->verifiableAssets,
            'oldest_successful_verification_at' => $this->oldestSuccessfulVerificationAt,
            'critical_items' => $this->criticalItems,
            'attention_items' => $this->attentionItems,
            'storage_unavailable' => $this->storageUnavailable,
        ];
    }
}
