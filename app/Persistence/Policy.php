<?php

declare(strict_types=1);

namespace App\Persistence;

/**
 * Declare whether a model mutation is unrestricted, requires execution authority, or is prohibited.
 */
enum Policy
{
    case Allowed;
    case Fenced;
    case Forbidden;
}
