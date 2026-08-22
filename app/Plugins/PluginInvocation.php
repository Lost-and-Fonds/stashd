<?php

declare(strict_types=1);

namespace App\Plugins;

final readonly class PluginInvocation
{
    public function __construct(
        public string $componentPath,
        public string $assetPath,
        public string $stagingPath,
        public string $operation = 'copy',
    ) {}
}
