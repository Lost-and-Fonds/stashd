<?php

declare(strict_types=1);

namespace App\Fixity;

final readonly class FixityVerificationResult
{
    public function __construct(
        public VerifyAssetOutcome $outcome,
        public ?string $expectedChecksum = null,
        public ?string $observedChecksum = null,
        public bool $restored = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'expected_checksum' => $this->expectedChecksum,
            'observed_checksum' => $this->observedChecksum,
            'restored' => $this->restored,
        ];
    }
}
