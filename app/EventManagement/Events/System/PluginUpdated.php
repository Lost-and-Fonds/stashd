<?php

declare(strict_types=1);

namespace App\EventManagement\Events\System;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Plugin was updated.
 * Plugin lifecycle and process observations do not establish Job completion or successful preservation.
 */
final readonly class PluginUpdated
{
    /**
     * Records that the Plugin was updated.
     */
    public function __construct(
        public string $pluginId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
