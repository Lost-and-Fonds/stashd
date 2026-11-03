<?php

declare(strict_types=1);

namespace App\Operations;

/**
 * Describe a physical handler episode without duplicating Durable's retry state.
 */
enum AttemptOutcome: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Interrupted = 'interrupted';
}
