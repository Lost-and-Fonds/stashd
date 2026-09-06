<?php

declare(strict_types=1);

namespace App\Fixity;

enum FixityStatus: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Mismatch = 'mismatch';
    case Missing = 'missing';
    case Verifying = 'verifying';
}
