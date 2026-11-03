<?php

declare(strict_types=1);

namespace App\Operations;

/**
 * Describe the admitted work's observable lifecycle without exposing queue internals.
 */
enum AssignmentState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
