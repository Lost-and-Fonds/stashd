<?php

declare(strict_types=1);

namespace Stashd\NativeRuntime\Capabilities;

final readonly class CredentialGrant
{
    public function __construct(public string $reference, public string $origin, public string $header, public string $secret) {}
}
