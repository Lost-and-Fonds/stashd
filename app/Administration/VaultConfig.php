<?php

declare(strict_types=1);

namespace App\Administration;

/**
 * Application-level settings for the one logical Vault.
 *
 * Provider-specific Tempest storage configuration is derived from this object.
 */
final readonly class VaultConfig
{
    /**
     * Hold the selected storage provider and root used to derive tagged Vault storage.
     */
    public function __construct(
        public string $provider,
        public string $root,
    ) {}
}
