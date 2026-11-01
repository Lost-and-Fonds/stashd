<?php

declare(strict_types=1);

namespace App\Operations;

/**
 * Return an immutable observation of durably admitted work to another department.
 */
final readonly class Assignment
{
    /**
     * Call Worker::find again for a fresh lifecycle observation.
     */
    public function __construct(
        public string $id,
        public AssignmentState $state,
        public Priority $priority,
        public ?string $error = null,
    ) {}
}
