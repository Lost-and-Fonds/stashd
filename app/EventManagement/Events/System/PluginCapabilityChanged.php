<?php

declare(strict_types=1);

namespace App\EventManagement\Events\System;

use App\EventManagement\DTO\CapabilityChange;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Plugin capability changed.
 * Plugin lifecycle and process observations do not establish Job completion or successful preservation.
 */
final readonly class PluginCapabilityChanged
{
    /**
     * Records that the Plugin capability changed.
     */
    public function __construct(
        public string $pluginId,
        public string $capabilityId,
        public CapabilityChange $change,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
