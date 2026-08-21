<?php

declare(strict_types=1);

namespace App\Plugins;

final readonly class PluginHelperGrant
{
    public function __construct(
        public string $name,
        public string $executable,
    ) {
    }
}
