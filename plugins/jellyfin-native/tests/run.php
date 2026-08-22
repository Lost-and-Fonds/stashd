<?php

declare(strict_types=1);

use Stashd\NativeRuntime\Capabilities\CredentialGrant;
use Stashd\NativeRuntime\Capabilities\FixtureTransport;
use Stashd\NativeRuntime\Capabilities\Invocation;
use Stashd\NativeRuntime\Capabilities\TransportResponse;
use Stashd\NativeRuntime\Package\PackageManager;
use Stashd\NativeRuntime\Runner\NativePluginRunner;
use Stashd\PluginSdk\Item;
use Stashd\PluginSdk\ItemResource;
use Stashd\PluginSdk\OptionValue;
use Stashd\PluginSdk\Setting;
use Stashd\PluginSdk\WireMapper;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

function jellyfinAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function jellyfinTemp(string $prefix): string
{
    $path = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(8));
    mkdir($path, 0700, true);

    return $path;
}

function jellyfinRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        chmod($path, 0600);
        unlink($path);

        return;
    }
    if (! is_dir($path)) {
        return;
    }
    chmod($path, 0700);
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            jellyfinRemove($path . '/' . $entry);
        }
    }
    rmdir($path);
}

function jellyfinArchive(string $source, string $archive): void
{
    $process = proc_open(['tar', '-czf', $archive, '-C', $source, 'plugin.json', 'plugin.php', 'src/JellyfinBroadcast.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    jellyfinAssert(is_resource($process) && proc_close($process) === 0, 'native Jellyfin package archive failed');
}

$root = jellyfinTemp('stashd-m8');
$source = dirname(__DIR__);
$process = null;
try {
    $manager = new PackageManager($root . '/plugins', '0.1', 'amd64');
    $archive = $root . '/jellyfin.tar.gz';
    jellyfinArchive($source, $archive);
    $manager->install($archive, hash_file('sha256', $archive));
    $manager->activate('jellyfin', '0.1.0');
    $package = $manager->activePath('jellyfin');
    jellyfinAssert($package !== null, 'native Jellyfin package was not activated');

    $staging = $root . '/staging';
    $materialized = false;
    $refreshFailure = false;
    $refreshes = 0;
    $transport = new FixtureTransport(static function (string $method, string $url, array $headers) use (&$materialized, &$refreshFailure, &$refreshes): TransportResponse {
        jellyfinAssert(($headers['X-Emby-Token'] ?? null) === 'fixture-secret', 'credential was not injected');
        if ($url === 'https://jellyfin.test/Library/MediaFolders' && $method === 'GET') {
            return new TransportResponse(200, ['content-type' => 'application/json'], [json_encode(['Items' => [['Id' => 'tv', 'Name' => 'TV'], ['Id' => 'movies', 'Name' => 'Movies']]], JSON_THROW_ON_ERROR)]);
        }
        if ($url === 'https://jellyfin.test/System/Info/Public' && $method === 'GET') {
            return new TransportResponse(200, ['content-type' => 'application/json'], [json_encode(['ServerName' => 'Fixture Jellyfin', 'Version' => '10.9'], JSON_THROW_ON_ERROR)]);
        }
        if ($url === 'https://jellyfin.test/Library/Refresh' && $method === 'POST') {
            jellyfinAssert($materialized, 'Jellyfin refresh occurred before materialization');
            if ($refreshFailure) {
                return new TransportResponse(503, [], ['fixture refresh failure']);
            }
            $refreshes++;

            return new TransportResponse(204, [], []);
        }

        return new TransportResponse(404, [], ['not found']);
    });
    $invocation = new Invocation($package, $staging, ['https://jellyfin.test'], [new CredentialGrant('jellyfin-api-token', 'https://jellyfin.test', 'X-Emby-Token', 'fixture-secret')], null, [], $transport);
    $process = (new NativePluginRunner($manager))->start('jellyfin', $staging);
    $capabilities = static function (array $message) use ($invocation): array {
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        return match ($message['method'] ?? '') {
            'http.request' => (function () use ($invocation, $params): array {
                $response = $invocation->http((string) ($params['method'] ?? 'GET'), (string) ($params['url'] ?? ''), is_array($params['headers'] ?? null) ? $params['headers'] : [], isset($params['body']) ? (string) $params['body'] : null, isset($params['credential']) ? (string) $params['credential'] : null);
                return ['status' => $response->status, 'headers' => $response->headers, 'body' => $response->body()];
            })(),
            'event.log', 'event.progress' => ['accepted' => true],
            default => throw new RuntimeException('unexpected capability: ' . ($message['method'] ?? '')),
        };
    };

    $settings = [new Setting('server_url', OptionValue::text('https://jellyfin.test')), new Setting('credential_name', OptionValue::text('jellyfin-api-token'))];
    $operation = $process->invoke('broadcast.operation', ['name' => 'discover-libraries', 'settings' => array_map(static fn(Setting $setting): array => ['key' => $setting->key, 'value' => $setting->value->toWire()], $settings), 'payload' => []], $capabilities);
    jellyfinAssert(($operation['choices'][0]['value'] ?? null) === 'tv', 'library discovery did not return generic choices');

    $request = WireMapper::publishRequest(new \Stashd\PluginSdk\PublishRequest('broadcast-1', $settings, [], [new Item('item-1', 'A/Title', [new ItemResource('asset-1', 'video')])]));
    $prepared = $process->invoke('broadcast.prepare', $request, $capabilities);
    jellyfinAssert(($prepared['artifacts'] ?? null) === [], 'native prepare did not remain a no-op');
    $publication = $process->invoke('broadcast.publish', $request, $capabilities);
    jellyfinAssert(($publication['files'][0]['relative-path'] ?? null) === 'Season 01/S01E01 - A_Title.mp4', 'native publication layout differs from Wasm');
    file_put_contents($staging . '/authoritative-output', 'materialized');
    $materialized = true;
    $finalized = $process->invoke('broadcast.finalize', ['request' => $request, 'publication' => $publication], $capabilities);
    jellyfinAssert(isset($finalized['files']), 'native finalize did not return publication');
    jellyfinAssert($refreshes === 1, 'native Jellyfin refresh count mismatch');

    $invalidCredential = $process->invoke('broadcast.operation', ['name' => 'test-connection', 'settings' => [['key' => 'server_url', 'value' => ['tag' => 'text', 'value' => 'https://jellyfin.test']], ['key' => 'credential_name', 'value' => ['tag' => 'text', 'value' => 'wrong-secret']]], 'payload' => []], $capabilities);
    jellyfinAssert(isset($invalidCredential['error']), 'invalid credential was not rejected');

    $refreshFailure = true;
    $failureProcess = (new NativePluginRunner($manager))->start('jellyfin', $staging);
    $failedFinalize = $failureProcess->invoke('broadcast.finalize', ['request' => $request, 'publication' => $publication], $capabilities);
    jellyfinAssert(isset($failedFinalize['error']) && is_file($staging . '/authoritative-output'), 'refresh failure did not preserve materialized output');
    $failureProcess->close();
    $refreshFailure = false;
    $rebuildProcess = (new NativePluginRunner($manager))->start('jellyfin', $staging);
    $rebuilt = $rebuildProcess->invoke('broadcast.publish', $request, $capabilities);
    jellyfinAssert(($rebuilt['files'][0]['relative-path'] ?? null) === ($publication['files'][0]['relative-path'] ?? null), 'rebuild changed publication layout');
    $rebuildProcess->close();

    $failedProcess = (new NativePluginRunner($manager))->start('jellyfin', $staging);
    $failed = $failedProcess->invoke('broadcast.operation', ['name' => 'discover-libraries', 'settings' => [['key' => 'server_url', 'value' => ['tag' => 'text', 'value' => 'https://denied.test']], ['key' => 'credential_name', 'value' => ['tag' => 'text', 'value' => 'jellyfin-api-token']]], 'payload' => []], $capabilities);
    jellyfinAssert(isset($failed['error']), 'undeclared Jellyfin destination was not denied');
    $failedProcess->close();
    $process->close();
    $invocation->close();
    echo "M8 native Jellyfin lifecycle: PASS\n";
} catch (Throwable $exception) {
    if ($process !== null) {
        fwrite(STDERR, "native plugin stderr:\n" . $process->stderr() . "\n");
    }
    throw $exception;
} finally {
    chmod($root, 0700);
    jellyfinRemove($root);
}
