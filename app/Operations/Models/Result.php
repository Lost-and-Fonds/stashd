<?php

declare(strict_types=1);

namespace App\Operations\Models;

use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;

/**
 * Coverage adopted by one authoritative Attempt after reconciling retained custody.
 */
#[Table('operation_results')]
final class Result
{
    public function __construct(
        public PrimaryKey $id,
        public string $assignment_id,
        public string $demand_id,
        public string $attempt_id,
        public string $coverage,
    ) {}
}
