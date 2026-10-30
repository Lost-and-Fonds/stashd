<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Scheduling;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the identified Input cooldown ended.
 * Dispatch and admission decisions do not establish that an Attempt started or completed.
 */
final readonly class SourceCooldownEnded
{
    /**
     * Records that the identified Input cooldown ended.
     *
     * @param string $inputId Input through which the source relationship is known.
     */
    public function __construct(
        public string $inputId,
        public string $cooldownId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
