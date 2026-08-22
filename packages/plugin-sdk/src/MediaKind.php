<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

enum MediaKind: string
{
    case Video = 'video';
    case Audio = 'audio';
}
