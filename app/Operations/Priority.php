<?php

declare(strict_types=1);

namespace App\Operations;

/**
 * Select the service class of admitted work, independently of transport names.
 */
enum Priority: string
{
    case Routine = 'routine';
    case Interactive = 'interactive';
}
