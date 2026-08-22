<?php

declare(strict_types=1);

namespace Stashd\NativeRuntime\Capabilities;

use Stashd\PluginSdk\HttpClient;
use Stashd\PluginSdk\HttpResponse;

final class BrokerHttpClient implements HttpClient
{
    public function __construct(private Invocation $invocation) {}

    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $credential = null): HttpResponse
    {
        return $this->invocation->http($method, $url, $headers, $body, $credential);
    }
}
