<?php

declare(strict_types=1);

use Stashd\NativeCapabilities\BrokerHttpClient;
use Stashd\NativeCapabilities\CapabilityDenied;
use Stashd\NativeCapabilities\CredentialGrant;
use Stashd\NativeCapabilities\FixtureTransport;
use Stashd\NativeCapabilities\HelperGrant;
use Stashd\NativeCapabilities\Invocation;
use Stashd\NativeCapabilities\InvocationClosed;
use Stashd\NativeCapabilities\TransportResponse;
use Stashd\NativeCapabilities\UnsafePath;

require_once __DIR__ . '/../m4/sdk/Sdk.php';
require_once __DIR__ . '/Capabilities.php';

function expectFailure(Closure $operation, string $type): void
{
    try {
        $operation();
        throw new RuntimeException('expected failure: ' . $type);
    } catch (Throwable $exception) {
        if (!$exception instanceof $type) {
            throw $exception;
        }
    }
}

function makeTempDirectory(string $prefix): string
{
    $path = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(8));
    if (!mkdir($path, 0700, true)) {
        throw new RuntimeException('could not create fixture directory');
    }
    return $path;
}

function removeDirectory(string $path): void
{
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (scandir($path) ?: [] as $entry) { if ($entry !== '.' && $entry !== '..') { removeDirectory($path . '/' . $entry); } }
    @rmdir($path);
}

$root = makeTempDirectory('stashd-m5');
$package = realpath(__DIR__ . '/fixture-package');
$outerCanary = getenv('M5_VAULT_CANARY');
if ($outerCanary === false || trim((string) file_get_contents('/vault/DO_NOT_READ')) !== $outerCanary) {
    throw new RuntimeException('M5 outer Vault canary was not installed');
}
$vault = $root . '/vault';
$staging = $root . '/staging';
mkdir($vault, 0700, true);
file_put_contents($vault . '/DO_NOT_READ', 'random-vault-canary-' . bin2hex(random_bytes(12)));
file_put_contents($vault . '/neighbor.bin', 'neighbor');
$assetRoot = $root . '/assets';
mkdir($assetRoot, 0700, true);
file_put_contents($assetRoot . '/granted.bin', 'granted-content');
file_put_contents($assetRoot . '/adjacent.bin', 'adjacent-content');
$secret = 'credential-secret-' . bin2hex(random_bytes(8));
$requests = [];
$transport = new FixtureTransport(static function (string $method, string $url, array $headers, ?string $body) use (&$requests, $secret): TransportResponse {
    $requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
    if ($url === 'https://allowed.test/redirect') {
        return new TransportResponse(302, ['Location' => 'https://forbidden.test/nope'], []);
    }
    if ($url === 'https://allowed.test/redirect-ok') {
        return new TransportResponse(302, ['Location' => '/final'], []);
    }
    if ($url === 'https://allowed.test/auth') {
        return new TransportResponse(($headers['X-Test-Token'] ?? null) === $secret ? 200 : 401, [], ['authenticated']);
    }
    if ($url === 'https://allowed.test/large') {
        return new TransportResponse(200, [], (static function (): Generator {
            yield str_repeat('a', 100_000);
            yield str_repeat('b', 100_000);
            yield str_repeat('c', 100_000);
        })());
    }
    if ($url === 'https://allowed.test/final') {
        return new TransportResponse(200, [], ['redirected-ok']);
    }
    return new TransportResponse(200, [], ['small-response']);
});
$invocation = new Invocation(
    $package,
    $staging,
    ['https://allowed.test'],
    [new CredentialGrant('fixture-token', 'https://allowed.test', 'X-Test-Token', $secret)],
    $assetRoot,
    [new HelperGrant('probe', 'helpers/probe.php')],
    $transport,
    64,
);
$http = new BrokerHttpClient($invocation);

$small = $http->request('GET', 'https://allowed.test/small');
assert($small->isInline() && $small->body() === 'small-response');
expectFailure(static fn () => $http->request('GET', 'https://forbidden.test/nope'), CapabilityDenied::class);
expectFailure(static fn () => $http->request('GET', 'https://allowed.test/redirect'), CapabilityDenied::class);
assert($http->request('GET', 'https://allowed.test/redirect-ok')->body() === 'redirected-ok');
$authenticated = $http->request('GET', 'https://allowed.test/auth', ['X-Test-Token' => 'spoof', 'Authorization' => 'spoof'], null, 'fixture-token');
assert($authenticated->status === 200 && $authenticated->body() === 'authenticated');
assert($requests[array_key_last($requests)]['headers']['X-Test-Token'] === $secret);
assert(($requests[array_key_last($requests)]['headers']['Authorization'] ?? null) === null);

$large = $http->request('GET', 'https://allowed.test/large');
assert(!$large->isInline() && $large->resource !== null);
$largeBody = '';
while (!$large->resource->isEof()) { $largeBody .= $large->resource->read(8192); }
assert(strlen($largeBody) === 300_000 && $largeBody[0] === 'a' && $largeBody[299_999] === 'c');
$large->resource->close();
assert(count(glob($staging . '/.resources/*') ?: []) === 0);
$failedResource = $http->request('GET', 'https://allowed.test/large')->resource;
try { throw new RuntimeException('simulated plugin failure'); } catch (RuntimeException) { $failedResource?->close(); }
assert(count(glob($staging . '/.resources/*') ?: []) === 0);
$cancelledResource = $http->request('GET', 'https://allowed.test/large')->resource;
assert($cancelledResource !== null);

$invocation->grantAsset('asset-1', $assetRoot . '/granted.bin');
$asset = $invocation->readAsset('asset-1');
assert($asset->read() === 'granted-content');
$asset->close();
expectFailure(static fn () => $invocation->readAsset('adjacent'), CapabilityDenied::class);
expectFailure(static fn () => $invocation->grantAsset('vault', $vault . '/DO_NOT_READ'), CapabilityDenied::class);

$stagingApi = $invocation->staging();
$output = $stagingApi->write('nested/output.bin', 'staged', 'application/octet-stream');
$descriptor = $stagingApi->output('nested/output.bin', 'application/octet-stream');
assert($output->sizeBytes === 6 && $descriptor->relativePath === 'nested/output.bin');
expectFailure(static fn () => $stagingApi->write('../escape', 'bad'), UnsafePath::class);
expectFailure(static fn () => $stagingApi->write('/absolute', 'bad'), UnsafePath::class);
$outside = $root . '/outside';
file_put_contents($outside, 'outside');
symlink($outside, $staging . '/link');
expectFailure(static fn () => $stagingApi->write('link/escape', 'bad'), UnsafePath::class);

$invocation->log('token=' . $secret);
$invocation->progress('download');
$eventJson = json_encode($invocation->events(), JSON_THROW_ON_ERROR);
assert(str_contains($eventJson, '[REDACTED]') && !str_contains($eventJson, $secret));

$helper = $invocation->runHelper('probe');
assert($helper->exitCode === 0);
assert(!str_contains($helper->stdout . $helper->stderr, $secret));
$helperReport = json_decode((string) file_get_contents($staging . '/helper-report.json'), true, 512, JSON_THROW_ON_ERROR);
assert($helperReport === ['vault' => 'denied', 'network' => 'denied', 'secret' => 'absent']);
assert(!str_contains(json_encode($helperReport, JSON_THROW_ON_ERROR), $outerCanary));
assert(!file_exists($package . '/MUTATION_TEST'));
expectFailure(static fn () => $invocation->runHelper('missing'), CapabilityDenied::class);
$badInvocation = new Invocation($package, makeTempDirectory('stashd-m5-bad'), [], [], null, [new HelperGrant('traversal', '../probe.php')]);
expectFailure(static fn () => $badInvocation->runHelper('traversal'), CapabilityDenied::class);
$badInvocation->close();

$invocation->cancel();
assert(!is_dir($staging));
expectFailure(static fn () => $http->request('GET', 'https://allowed.test/small'), InvocationClosed::class);
expectFailure(static fn () => $invocation->staging(), InvocationClosed::class);
expectFailure(static fn () => $cancelledResource->read(), InvocationClosed::class);

removeDirectory($root);
echo "M5 broker capability conformance: PASS\n";
