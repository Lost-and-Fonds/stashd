<?php

declare(strict_types=1);

namespace App\EventManagement\DTO;

use InvalidArgumentException;

/**
 * Binds an Asset identity to one exact historical OCFL version.
 * A preferred or latest-version selector cannot be used as a preservation pin.
 */
final readonly class AssetVersionReference
{
    /**
     * Creates an exact pin without resolving mutable preferences or reading the Vault.
     * Callers remain responsible for establishing that this version actually exists.
     */
    public function __construct(
        public string $assetId,
        public string $version,
    ) {
        if ($assetId === '' || preg_match('/^v[1-9][0-9]*$/D', $version) !== 1) {
            throw new InvalidArgumentException('An Asset pin requires an identity and an exact OCFL version.');
        }
    }
}
