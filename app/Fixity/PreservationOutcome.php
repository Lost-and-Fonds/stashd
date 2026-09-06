<?php

declare(strict_types=1);

namespace App\Fixity;

enum PreservationOutcome: string
{
    case Success = 'success';
    case Mismatch = 'mismatch';
    case Missing = 'missing';
    case Unverified = 'unverified';
}
