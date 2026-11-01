<?php

declare(strict_types=1);

namespace App\Operations\Models;

use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;

/**
 * A protected progress marker, retained with the Attempt that published it.
 */
#[Table('operation_checkpoints')]
final class Checkpoint
{
    public function __construct(
        public PrimaryKey $id,
        public string $assignment_id,
        public string $demand_id,
        public string $attempt_id,
        public string $value,
    ) {}
}
