<?php

declare(strict_types=1);

namespace App\EventManagement\Events\System;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Plugin was updated.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class PluginUpdated
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $pluginId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
