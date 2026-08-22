<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

enum PluginErrorCode: string
{
    case Unsupported = 'unsupported';
    case NotFound = 'not-found';
    case Authentication = 'authentication';
    case RateLimited = 'rate-limited';
    case Unavailable = 'unavailable';
    case InvalidData = 'invalid-data';
    case Failed = 'failed';
}
