<?php

declare(strict_types=1);

namespace App\Broadcasts;

enum BroadcastTriggerType: string
{
    case PlexScan = 'plex_scan';
    case Webhook = 'webhook';
}
