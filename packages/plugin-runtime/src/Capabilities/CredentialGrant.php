<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Capabilities;

final readonly class CredentialGrant
{
    public function __construct(
        public string $reference,
        public string $origin,
        public string $parameter,
        public string $secret,
        public string $placement = 'header',
    ) {}
}
