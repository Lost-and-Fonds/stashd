<?php

declare(strict_types=1);

namespace Tests\Support\Durable;

/**
 * Supply only blocking fault controls to the independently executing probe handler.
 */
final readonly class DepartmentControl
{
    /**
     * Keep production execution and ownership unmodified by test synchronization.
     */
    public function __construct(
        public ?string $barrier = null,
        public bool $fail = false,
        public bool $insideMutation = false,
    ) {}
}
