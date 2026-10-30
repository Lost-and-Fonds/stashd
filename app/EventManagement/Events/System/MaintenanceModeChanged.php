<?php

declare(strict_types=1);

namespace App\EventManagement\Events\System;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that maintenance mode actually changed to the supplied enabled state.
 * Plugin lifecycle and process observations do not establish Job completion or successful preservation.
 */
final readonly class MaintenanceModeChanged
{
    /**
     * Records that maintenance mode actually changed to the supplied enabled state.
     */
    public function __construct(
        public bool $enabled,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
