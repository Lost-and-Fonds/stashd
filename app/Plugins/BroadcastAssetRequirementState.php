<?php

declare(strict_types=1);

namespace App\Plugins;

enum BroadcastAssetRequirementState: string
{
    case Ready = 'ready';
    case Pending = 'pending';
    case PermanentlyUnavailable = 'permanently_unavailable';
}
