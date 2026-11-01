<?php

declare(strict_types=1);

namespace App\Operations\Models;

use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;

/**
 * Record which retained custody receipt a particular execution independently verified.
 */
#[Table('operation_reconciliations')]
final class Reconciliation
{
    public PrimaryKey $id;
    public string $attempt_id;
    public string $artifact_id;
}
