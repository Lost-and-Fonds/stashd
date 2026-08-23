<?php

declare(strict_types=1);

use Stashd\PluginRuntime\Capabilities\CapabilityDenied;
use Stashd\PluginRuntime\Capabilities\FixtureTransport;
use Stashd\PluginRuntime\Capabilities\Invocation;
use Stashd\PluginRuntime\Capabilities\TransportResponse;

function httpCapabilityInvocation(array $responses, string $prefix = 'https://example.test/api/foo/'): Invocation
{
    $root = sys_get_temp_dir() . '/stashd-http-' . bin2hex(random_bytes(5));
    mkdir($root, 0700, true);
    $index = 0;

    return new Invocation($root, $root . '/stage', [$prefix], transport: new FixtureTransport(static function (string $method, string $url, array $headers) use (&$index, $responses): TransportResponse {
        return $responses[$index++] ?? new TransportResponse(200, [], ['ok']);
    }));
}

it('enforces full redirect prefixes and resolves relative locations', function (): void {
    $invocation = httpCapabilityInvocation([
        new TransportResponse(302, ['Location' => 'bar?x=1#fragment'], []),
        new TransportResponse(200, [], ['ok']),
    ]);

    expect($invocation->http('GET', 'https://example.test/api/foo/start')->body)->toBe('ok');
    $invocation->close();
});

it('denies redirects outside the granted path or host before sending credentials', function (): void {
    $invocation = httpCapabilityInvocation([new TransportResponse(302, ['Location' => 'https://evil.test/steal'], [])]);

    expect(fn(): mixed => $invocation->http('GET', 'https://example.test/api/foo/start'))->toThrow(CapabilityDenied::class);
    $invocation->close();
});

it('enforces the redirect limit', function (): void {
    $invocation = httpCapabilityInvocation([
        new TransportResponse(302, ['Location' => '/api/foo/one'], []),
        new TransportResponse(302, ['Location' => '/api/foo/two'], []),
        new TransportResponse(302, ['Location' => '/api/foo/three'], []),
        new TransportResponse(302, ['Location' => '/api/foo/four'], []),
    ]);

    expect(fn(): mixed => $invocation->http('GET', 'https://example.test/api/foo/start'))->toThrow(CapabilityDenied::class);
    $invocation->close();
});
