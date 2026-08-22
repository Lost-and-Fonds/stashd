<?php

declare(strict_types=1);

namespace App\Plugins;

final readonly class PluginHttpGrant
{
    /** @param list<string> $allowedPrefixes */
    public function __construct(
        public array $allowedPrefixes,
        public ?PluginCredentialGrant $credential = null,
    ) {}
}
