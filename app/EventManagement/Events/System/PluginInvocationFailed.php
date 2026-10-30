<?php

declare(strict_types=1);

namespace App\EventManagement\Events\System;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records a failed plugin invocation using a safe reason code. It does not claim that preservation failed if independent archival evidence exists.
 * Plugin lifecycle and process observations do not establish Job completion or successful preservation.
 */
final readonly class PluginInvocationFailed
{
    /**
     * Records a failed plugin invocation using a safe reason code. It does not claim that preservation failed if independent archival evidence exists.
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
