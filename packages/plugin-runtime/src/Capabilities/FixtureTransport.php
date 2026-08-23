<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Capabilities;

final class FixtureTransport implements HostHttpTransport
{
    /** @var callable(string, string, array<string, string>, ?string):TransportResponse|null */
    private $handler;

    public function __construct(?callable $handler = null)
    {
        $this->handler = $handler;
    }

    public function request(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        if ($this->handler !== null) {
            return ($this->handler)($method, $url, $headers, $body);
        }

        return new TransportResponse(200, ['content-type' => 'text/plain'], ['fixture']);
    }
}
