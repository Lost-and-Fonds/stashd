<?php

declare(strict_types=1);

namespace App\Timeline;

enum TimelineEntryCategory: string
{
    case Chapter = 'chapter';
    case Other = 'other';
}
