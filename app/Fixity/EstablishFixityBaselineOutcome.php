<?php

declare(strict_types=1);

namespace App\Fixity;

enum EstablishFixityBaselineOutcome: string
{
    case Established = 'established';
    case AlreadyHasBaseline = 'already_has_baseline';
    case NotEligible = 'not_eligible';
    case StorageUnavailable = 'storage_unavailable';
    case FileUnavailable = 'file_unavailable';
    case ChecksumFailed = 'checksum_failed';
    case NotFound = 'not_found';
}
