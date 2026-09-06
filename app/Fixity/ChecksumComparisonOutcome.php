<?php

declare(strict_types=1);

namespace App\Fixity;

enum ChecksumComparisonOutcome: string
{
    case Match = 'match';
    case Mismatch = 'mismatch';
    case Unverified = 'unverified';
    case Unavailable = 'unavailable';
}
