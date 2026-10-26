<?php

declare(strict_types=1);

namespace App\Integration;

use App\Integration\DTO\AcquiredFile;
use App\Integration\DTO\DiscoveredItem;

/**
 * Define the in-process acquisition boundary exercised by the Filesystem adapter.
 *
 * Adapters return host-owned staged bytes, never publish canonical Vault Objects.
 */
interface InputAcquisition
{
    /**
     * Capture one discovery into the supplied invocation and return its accepted receipt.
     *
     * The adapter closes its own source streams. The caller owns staging cleanup,
     * validates the result, and adopts it only after this method returns successfully.
     *
     * @param array<string, mixed> $options Component settings from the configured Input.
     */
    public function acquire(string $inputId, array $options, DiscoveredItem $item, AcquisitionStaging $staging): AcquiredFile;
}
