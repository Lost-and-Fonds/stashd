<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Providers\ResolvedInput;

interface SourceResolvingInputProvider
{
    /** @param array<string, bool|int|string> $source */
    public function resolveSource(array $source): ResolvedInput;
}
