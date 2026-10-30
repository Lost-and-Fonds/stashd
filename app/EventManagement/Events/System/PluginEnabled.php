<?php

declare(strict_types=1);

namespace App\EventManagement\Events\System;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the installed plugin became enabled for use.
 * Plugin lifecycle and process observations do not establish Job completion or successful preservation.
 */
final readonly class PluginEnabled
{
    /**
     * Records that the installed plugin became enabled for use.
     */
    public function __construct(
        public string $pluginId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
