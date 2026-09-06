<?php

declare(strict_types=1);

namespace App\Fixity;

enum VerifyAssetOutcome: string
{
    case Ok = 'ok';
    case Missing = 'missing';
    case ChecksumMismatch = 'checksum_mismatch';
    case Unverified = 'unverified';
    case Restored = 'restored';
    case Skipped = 'skipped';
    case NotFound = 'not_found';
    case StorageUnavailable = 'storage_unavailable';
}
