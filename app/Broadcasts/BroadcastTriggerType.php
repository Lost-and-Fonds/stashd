<?php

declare(strict_types=1);

namespace App\Broadcasts;

/** @internal Retained because the released domain migration references it. */
enum BroadcastTriggerType: string
{
    case JellyfinScan = 'jellyfin_scan';
    case PlexScan = 'plex_scan';
    case Webhook = 'webhook';
}
