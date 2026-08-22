<?php

declare(strict_types=1);

namespace App\Vault;

final readonly class VaultVerifyResult
{
    public function __construct(
        public int $checked,
        public int $missing,
        public int $restored,
        public int $checksumMismatch,
        public bool $storageUnavailable,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'checked' => $this->checked,
            'missing' => $this->missing,
            'restored' => $this->restored,
            'checksum_mismatch' => $this->checksumMismatch,
            'storage_unavailable' => $this->storageUnavailable,
        ];
    }
}
