<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

enum DiscoveryIntent: string
{
    case Refresh = 'refresh';
    case Complete = 'complete';
}
