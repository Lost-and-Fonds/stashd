<?php

declare(strict_types=1);

namespace App\EventManagement\DTO;

/**
 * Describes whether a plugin capability became available or was withdrawn.
 * It records lifecycle change without claiming that follow-up work completed.
 */
enum CapabilityChange: string
{
    /** The plugin began providing the capability. */
    case ADDED = 'added';

    /** The plugin stopped providing the capability. */
    case REMOVED = 'removed';
}
