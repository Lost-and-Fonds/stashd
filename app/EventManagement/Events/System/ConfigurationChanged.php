<?php

declare(strict_types=1);

namespace App\EventManagement\Events\System;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Configuration Changed changed.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class ConfigurationChanged
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $configurationKey,
        public string $changeReference,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
