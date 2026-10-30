<?php

declare(strict_types=1);

namespace App\EventManagement\Events\System;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records an applied configuration change using a safe change reference rather than configuration values.
 * Plugin lifecycle and process observations do not establish Job completion or successful preservation.
 */
final readonly class ConfigurationChanged
{
    /**
     * Records an applied configuration change using a safe change reference rather than configuration values.
     */
    public function __construct(
        public string $configurationKey,
        public string $changeReference,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
