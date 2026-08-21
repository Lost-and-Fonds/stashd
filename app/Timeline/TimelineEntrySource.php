<?php

declare(strict_types=1);

namespace App\Timeline;

enum TimelineEntrySource: string
{
    case Provider = 'provider';
    case Ytdlp = 'yt_dlp';
}
