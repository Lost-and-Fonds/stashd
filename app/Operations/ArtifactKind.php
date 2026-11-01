<?php

declare(strict_types=1);

namespace App\Operations;

/**
 * Distinguish execution checkpoints, acknowledged custody and adopted results.
 */
enum ArtifactKind: string
{
    case Checkpoint = 'checkpoint';
    case Custody = 'custody';
    case Result = 'result';
}
