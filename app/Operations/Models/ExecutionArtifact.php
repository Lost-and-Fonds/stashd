<?php

declare(strict_types=1);

namespace App\Operations\Models;

use App\Operations\ArtifactKind;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;

/**
 * Persist acknowledged domain evidence independently of worker lifetime and journal replay.
 */
#[Table('operation_artifacts')]
final class ExecutionArtifact
{
    public PrimaryKey $id;
    public string $assignment_id;
    public string $demand_id;
    public string $attempt_id;
    public ArtifactKind $kind;
    public string $value;
    public ?string $path = null;
    public ?string $digest = null;
}
