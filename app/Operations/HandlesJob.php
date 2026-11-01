<?php

declare(strict_types=1);

namespace App\Operations;

use Attribute;

/**
 * Associate one injectable invokable handler with a concrete Job class.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class HandlesJob
{
    /**
     * Retain the supported request class for discovery and validation at admission.
     *
     * @param class-string<Job> $job
     */
    public function __construct(
        public string $job,
    ) {}
}
