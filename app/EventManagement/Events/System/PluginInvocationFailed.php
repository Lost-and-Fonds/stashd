<?php

declare(strict_types=1);

namespace App\EventManagement\Events\System;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records a failed plugin invocation using a safe reason code. It does not claim that preservation failed if independent archival evidence exists.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class PluginInvocationFailed
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $pluginId,
        public string $invocationId,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
