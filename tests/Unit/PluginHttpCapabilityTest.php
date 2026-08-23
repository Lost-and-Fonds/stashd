<?php

declare(strict_types=1);

use Stashd\PluginRuntime\Capabilities\CapabilityDenied;
use Stashd\PluginRuntime\Capabilities\CredentialGrant;
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

it('allows a direct request inside the granted prefix', function (): void {
    $invocation = httpCapabilityInvocation([new TransportResponse(200, [], ['ok'])]);

    expect($invocation->http('GET', 'https://example.test/api/foo/direct')->body())->toBe('ok');
    $invocation->close();
});

it('enforces full redirect prefixes and resolves relative locations', function (): void {
    $invocation = httpCapabilityInvocation([
        new TransportResponse(302, ['Location' => './nested/../bar?x=1#fragment'], []),
        new TransportResponse(200, [], ['ok']),
    ]);

    expect($invocation->http('GET', 'https://example.test/api/foo/start')->body())->toBe('ok');
    $invocation->close();
});

it('denies redirects outside the granted path or host before sending credentials', function (): void {
    $sent = [];
    $root = sys_get_temp_dir() . '/stashd-http-' . bin2hex(random_bytes(5));
    mkdir($root, 0700, true);
    $invocation = new Invocation($root, $root . '/stage', ['https://example.test/api/foo/'], [new CredentialGrant('token', 'https://example.test', 'Authorization', 'secret')], transport: new FixtureTransport(static function (string $method, string $url, array $headers) use (&$sent): TransportResponse {
        $sent[] = [$url, $headers];

        return new TransportResponse(302, ['Location' => 'https://evil.test/steal'], []);
    }));

    expect(fn(): mixed => $invocation->http('GET', 'https://example.test/api/foo/start', credential: 'token'))->toThrow(CapabilityDenied::class);
    expect($sent)->toHaveCount(1);
    expect($sent[0][1]['Authorization'] ?? null)->toBe('secret');
    $invocation->close();
});

it('denies a same-host redirect outside the granted path', function (): void {
    $invocation = httpCapabilityInvocation([new TransportResponse(302, ['Location' => 'https://example.test/api/bar'], [])]);

    expect(fn(): mixed => $invocation->http('GET', 'https://example.test/api/foo/start'))->toThrow(CapabilityDenied::class);
    $invocation->close();
});

it('preserves query handling while injecting granted query credentials', function (): void {
    $seen = [];
    $root = sys_get_temp_dir() . '/stashd-http-' . bin2hex(random_bytes(5));
    mkdir($root, 0700, true);
    $invocation = new Invocation($root, $root . '/stage', ['https://example.test/api/foo/'], [new CredentialGrant('token', 'https://example.test', 'token', 'secret', 'query')], transport: new FixtureTransport(static function (string $method, string $url, array $headers) use (&$seen): TransportResponse {
        $seen[] = $url;

        return new TransportResponse(200, [], ['ok']);
    }));

    expect($invocation->http('GET', 'https://example.test/api/foo/item?existing=value', credential: 'token')->body())->toBe('ok');
    expect($seen[0])->toBe('https://example.test/api/foo/item?existing=value&token=secret');
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
