<?php

declare(strict_types=1);

namespace App\EventManagement\DTO;

/**
 * Describes how much of the declared discovery scope a completed run covered.
 * It never implies an Item count that the source did not report.
 */
enum DiscoveryCoverage: string
{
    /** The run covered the full declared discovery scope. */
    case EXHAUSTIVE = 'exhaustive';

    /** The run completed with only part of its declared scope covered. */
    case PARTIAL = 'partial';

    /** Available evidence could not establish whether the declared scope was complete. */
    case INDETERMINATE = 'indeterminate';
}
