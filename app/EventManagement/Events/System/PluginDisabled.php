<?php

declare(strict_types=1);

namespace App\EventManagement\Events\System;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the installed plugin became disabled for use.
 * Plugin lifecycle and process observations do not establish Job completion or successful preservation.
 */
final readonly class PluginDisabled
{
    /**
     * Records that the installed plugin became disabled for use.
     */
    public function __construct(
        public string $pluginId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
