<?php

declare(strict_types=1);

namespace App\EventManagement\Events\System;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the plugin installation completed and its identity is registered.
 * Plugin lifecycle and process observations do not establish Job completion or successful preservation.
 */
final readonly class PluginInstalled
{
    /**
     * Records that the plugin installation completed and its identity is registered.
     */
    public function __construct(
        public string $pluginId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
