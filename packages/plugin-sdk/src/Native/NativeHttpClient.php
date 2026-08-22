<?php

declare(strict_types=1);

namespace Stashd\PluginSdk\Native;

use Closure;
use Stashd\PluginSdk\HttpClient;
use Stashd\PluginSdk\HttpResponse;

final readonly class NativeHttpClient implements HttpClient
{
    /** @param callable(string,array<string,mixed>):array<string,mixed> $call */
    public function __construct(private Closure $call) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $credential = null): HttpResponse
    {
        $result = ($this->call)('http.request', [
            'method' => strtoupper($method), 'url' => $url, 'headers' => $headers,
            'body' => $body, 'credential' => $credential,
        ]);
        $resource = isset($result['resource']) && is_string($result['resource'])
            ? new NativeReadableResource($this->call, $result['resource'])
            : null;

        return new HttpResponse(
            (int) ($result['status'] ?? 0),
            is_array($result['headers'] ?? null) ? $result['headers'] : [],
            isset($result['body']) ? (string) $result['body'] : null,
            $resource,
        );
    }
}
