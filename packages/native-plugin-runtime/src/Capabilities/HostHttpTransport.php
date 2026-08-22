<?php

declare(strict_types=1);

namespace Stashd\NativeRuntime\Capabilities;

interface HostHttpTransport
{
    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers, ?string $body): TransportResponse;
}
