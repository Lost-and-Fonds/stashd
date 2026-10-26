<?php

declare(strict_types=1);

namespace App\Integration;

/**
 * State whether the successful publication reports its complete filesystem result.
 */
enum FileReportStatus: string
{
    case Complete = 'complete';
    case NotApplicable = 'not-applicable';
}
