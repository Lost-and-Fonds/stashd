<?php

declare(strict_types=1);

namespace App\Providers;

enum InputOptionType: string
{
    case Bool = 'bool';
    case Enum = 'enum';
    case Text = 'text';
}
