<?php

declare(strict_types=1);

namespace App\Connections;

enum ConnectionState: string
{
    case Ready = 'ready';
    case Failed = 'failed';
    case Disabled = 'disabled';
}
