<?php

declare(strict_types=1);

namespace App\EventManagement\Events\System;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records a protocol violation by the identified plugin invocation using a safe reason code.
 * Plugin lifecycle and process observations do not establish Job completion or successful preservation.
 */
final readonly class PluginProtocolViolationDetected
{
    /**
     * Records a protocol violation by the identified plugin invocation using a safe reason code.
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
