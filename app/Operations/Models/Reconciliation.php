<?php

declare(strict_types=1);

namespace App\Operations\Models;

use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;

/**
 * Durable evidence that this Attempt independently verified a retained custody receipt.
 */
#[Table('operation_reconciliations')]
final class Reconciliation
{
    public function __construct(
        public PrimaryKey $id,
        public string $attempt_id,
        public string $receipt_id,
    ) {}
}
