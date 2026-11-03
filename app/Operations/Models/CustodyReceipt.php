<?php

declare(strict_types=1);

namespace App\Operations\Models;

use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;

/**
 * Acknowledged staged bytes; retaining and publishing the files remain separate duties.
 */
#[Table('operation_custody_receipts')]
final class CustodyReceipt
{
    public function __construct(
        public PrimaryKey $id,
        public string $assignment_id,
        public string $demand_id,
        public string $attempt_id,
        public string $key,
        public string $path,
        public string $digest,
    ) {}
}
