<?php

declare(strict_types=1);

namespace App\EventManagement\Events\System;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records termination of the plugin process associated with the invocation.
 * Plugin lifecycle and process observations do not establish Job completion or successful preservation.
 */
final readonly class PluginProcessTerminated
{
    /**
     * Records termination of the plugin process associated with the invocation.
     *
     * @param string $invocationId Plugin invocation responsible for this observation.
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $pluginId,
        public string $invocationId,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
