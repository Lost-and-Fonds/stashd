<?php

declare(strict_types=1);

namespace App\MediaServers;

/** @internal Retained because the released domain migration references it. */
enum MediaServerType: string
{
    case Jellyfin = 'jellyfin';
    case Plex = 'plex';
}
