<?php

declare(strict_types=1);

namespace App\Fixity;

enum PreservationHealth: string
{
    case Healthy = 'healthy';
    case Attention = 'attention';
    case Checking = 'checking';
    case Critical = 'critical';
    case Unknown = 'unknown';
}
