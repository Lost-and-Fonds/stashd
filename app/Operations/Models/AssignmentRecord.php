<?php

declare(strict_types=1);

namespace App\Operations\Models;

use App\Operations\Priority;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;

/**
 * Persist admission and satisfaction of canonical Work, not Durable's execution state.
 */
#[Table('operation_assignments')]
final class AssignmentRecord
{
    public PrimaryKey $id;
    public string $work_type;
    public string $work_scope;
    public string $work_mode;
    public Priority $priority;
    public bool $active = true;
    public ?string $error = null;
}
