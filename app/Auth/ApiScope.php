<?php

declare(strict_types=1);

namespace App\Auth;

enum ApiScope: string
{
    case ProfileRead = 'profile:read';
    case SystemRead = 'system:read';
    case JobsRead = 'jobs:read';
    case ActivityRead = 'activity:read';
    case MediaRead = 'media:read';
    case StashRead = 'stash:read';
    case StashWrite = 'stash:write';
    case BroadcastRead = 'broadcast:read';
    case BroadcastWrite = 'broadcast:write';
    case ConnectionsRead = 'connections:read';
    case ConnectionsWrite = 'connections:write';
    /** @deprecated Historical token scope retained for old credentials. */
    case MediaServerRead = 'media-server:read';
    /** @deprecated Historical token scope retained for old credentials. */
    case MediaServerWrite = 'media-server:write';
    case CommandsCreate = 'commands:create';
    case TokensManage = 'tokens:manage';
}
