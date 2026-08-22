<?php

declare(strict_types=1);

namespace App\Plugins;

final readonly class PluginCredentialGrant
{
    public function __construct(
        public string $name,
        public string $value,
        public string $parameter,
        public string $placement = 'query',
    ) {
    }
}
