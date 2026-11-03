<?php

declare(strict_types=1);

namespace App\Operations;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class HandlesJob
{
    /**
     * The stable type alias survives PHP class renames; retain compatible input fields.
     *
     * @param class-string<Job> $job
     */
    public function __construct(
        public string $job,
        public string $type,
    ) {}
}
