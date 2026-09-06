<?php

declare(strict_types=1);

namespace App\Fixity;

enum PreservationEventType: string
{
    case FixityGenerated = 'fixity_generated';
    case FixityCheck = 'fixity_check';
}
