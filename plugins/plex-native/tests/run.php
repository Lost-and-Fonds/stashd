<?php

declare(strict_types=1);

use Stashd\NativeRuntime\Capabilities\CredentialGrant;
use Stashd\NativeRuntime\Capabilities\FixtureTransport;
use Stashd\NativeRuntime\Capabilities\Invocation;
use Stashd\NativeRuntime\Capabilities\TransportResponse;
use Stashd\NativeRuntime\Package\PackageManager;
use Stashd\NativeRuntime\Runner\NativePluginRunner;
use Stashd\PluginSdk as Sdk;
use Stashd\PluginSdk\WireMapper;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

function plexAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function plexTemp(string $prefix): string
{
    $path = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(8));
    mkdir($path, 0700, true);

    return $path;
}

function plexRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    if (! is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            plexRemove($path . '/' . $entry);
        }
    }
    rmdir($path);
}

function plexArchive(string $source, string $archive): void
{
    $process = proc_open(
        ['tar', '-czf', $archive, '-C', $source, 'plugin.json', 'plugin.php', 'src/PlexBroadcast.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    plexAssert(is_resource($process) && proc_close($process) === 0, 'native Plex package archive failed');
}

$root = plexTemp('stashd-m9');
$source = dirname(__DIR__);
$process = null;
$failureProcess = null;
$transport = null;
$invocation = null;
$materialized = false;
$refreshFailure = false;
$malformed = false;
try {
    $manager = new PackageManager($root . '/plugins', '0.1', 'amd64');
    $archive = $root . '/plex.tar.gz';
    plexArchive($source, $archive);
    $manager->install($archive, hash_file('sha256', $archive));
    $manager->activate('plex', '0.1.0');
    $package = $manager->activePath('plex');
    plexAssert($package !== null, 'native Plex package was not activated');

    $transport = new FixtureTransport(static function (string $method, string $url, array $headers) use (&$materialized, &$refreshFailure, &$malformed): TransportResponse {
        plexAssert(($headers['X-Plex-Token'] ?? null) === null, 'Plex token was exposed as a header');
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        plexAssert(($query['X-Plex-Token'] ?? null) === 'fixture-secret', 'Plex query credential was not injected');
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '/redirect') {
            return new TransportResponse(302, ['Location' => 'https://denied.test/identity'], []);
        }
        if ($path === '/identity') {
            $identity = $malformed ? '<broken' : (file_get_contents(__DIR__ . '/../../../tests/fixtures/media_servers/http/plex/identity.json') ?: '');

            return new TransportResponse(200, ['content-type' => 'application/xml'], [$identity]);
        }
        if ($path === '/library/sections') {
            return new TransportResponse(200, ['content-type' => 'application/xml'], [file_get_contents(__DIR__ . '/../../../tests/fixtures/media_servers/http/plex/sections.xml') ?: '']);
        }
        if (preg_match('#^/library/sections/([^/]+)/refresh$#', $path) === 1) {
            plexAssert($materialized, 'Plex refresh occurred before materialization');

            return new TransportResponse($refreshFailure ? 503 : 204, [], []);
        }

        return new TransportResponse(404, [], ['not found']);
    });
    $settings = [
        new Sdk\Setting('server_url', Sdk\OptionValue::text('https://plex.test')),
        new Sdk\Setting('credential_name', Sdk\OptionValue::text('plex-api-token')),
        new Sdk\Setting('library_id', Sdk\OptionValue::text('1')),
        new Sdk\Setting('captions', Sdk\OptionValue::text('creator_only')),
        new Sdk\Setting('caption_languages', Sdk\OptionValue::text('en,fr')),
        new Sdk\Setting('title', Sdk\OptionValue::text('A & <Show>')),
    ];
    $staging = $root . '/staging';
    $invocation = new Invocation($package, $staging, ['https://plex.test'], [new CredentialGrant('plex-api-token', 'https://plex.test', 'X-Plex-Token', 'fixture-secret', 'query')], null, [], $transport);
    $runner = new NativePluginRunner($manager);
    $process = $runner->start('plex', $staging);
    $capabilities = static function (array $message) use ($invocation): array {
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($message['method'] ?? '') {
            'http.request' => (function () use ($invocation, $params): array {
                $response = $invocation->http((string) ($params['method'] ?? 'GET'), (string) ($params['url'] ?? ''), is_array($params['headers'] ?? null) ? $params['headers'] : [], isset($params['body']) ? (string) $params['body'] : null, isset($params['credential']) ? (string) $params['credential'] : null);

                return ['status' => $response->status, 'headers' => $response->headers, 'body' => $response->body()];
            })(),
            'staging.write' => (function () use ($invocation, $params): array {
                $content = base64_decode((string) ($params['content'] ?? ''), true);
                plexAssert($content !== false, 'staging content was invalid');
                $artifact = $invocation->staging()->write((string) ($params['relative_path'] ?? ''), $content, isset($params['media_type']) ? (string) $params['media_type'] : null);

                return ['reference' => $artifact->reference, 'media_type' => $artifact->mediaType, 'size_bytes' => $artifact->sizeBytes];
            })(),
            'staging.stage' => (function () use ($invocation, $params): array {
                $artifact = $invocation->staging()->stage((string) ($params['relative_path'] ?? ''), isset($params['media_type']) ? (string) $params['media_type'] : null);

                return ['reference' => $artifact->reference, 'media_type' => $artifact->mediaType, 'size_bytes' => $artifact->sizeBytes];
            })(),
            'event.log', 'event.progress' => ['accepted' => true],
            default => throw new RuntimeException('unexpected capability: ' . ($message['method'] ?? '')),
        };
    };

    $operation = $process->invoke('broadcast.operation', ['name' => 'discover-libraries', 'settings' => array_map(static fn(Sdk\Setting $setting): array => ['key' => $setting->key, 'value' => $setting->value->toWire()], $settings), 'payload' => []], $capabilities);
    plexAssert(($operation['choices'][0]['value'] ?? null) === '1', 'Plex library discovery did not return the first choice');
    plexAssert(($operation['choices'][2]['label'] ?? null) === 'Library', 'Plex missing title default was not applied');

    $request = new Sdk\PublishRequest(
        'broadcast-1',
        $settings,
        [new Sdk\Source('input-1', [new Sdk\Setting('season', Sdk\OptionValue::number(3))])],
        [
            new Sdk\Item('item-missing-video', 'Skipped', [], 'input-1'),
            new Sdk\Item('item-1', 'A & <Title>/?', [new Sdk\ItemResource('video-1', 'video', mediaType: 'video/webm'), new Sdk\ItemResource('subtitle-1', 'subtitle', mediaType: 'text/vtt')], 'input-1'),
        ],
    );
    $wireRequest = WireMapper::publishRequest($request);
    $publication = $process->invoke('broadcast.publish', $wireRequest, $capabilities);
    plexAssert(($publication['files'][0]['relative-path'] ?? null) === 'Season 03/S03E002 - A & _Title___.webm', 'native Plex video layout differs from Wasmtime');
    plexAssert(($publication['files'][1]['relative-path'] ?? null) === 'Season 03/S03E002 - A & _Title___.en.vtt', 'native Plex caption layout differs from Wasmtime');
    $nfo = file_get_contents($staging . '/tvshow.nfo') ?: '';
    plexAssert(str_contains($nfo, '&amp;') && str_contains($nfo, '&lt;'), 'Plex NFO was not XML escaped');
    $materialized = true;
    $finalized = $process->invoke('broadcast.finalize', ['request' => $wireRequest, 'publication' => $publication], $capabilities);
    plexAssert(isset($finalized['files']), 'native Plex finalize did not return publication');

    $refreshFailure = true;
    $failedFinalize = $process->invoke('broadcast.finalize', ['request' => $wireRequest, 'publication' => $publication], $capabilities);
    plexAssert(isset($failedFinalize['error']) && is_file($staging . '/tvshow.nfo'), 'Plex refresh failure did not preserve materialized output');
    $refreshFailure = false;
    unlink($staging . '/tvshow.nfo');
    $rebuild = $process->invoke('broadcast.publish', $wireRequest, $capabilities);
    plexAssert(($rebuild['files'][0]['relative-path'] ?? null) === ($publication['files'][0]['relative-path'] ?? null), 'Plex rebuild changed publication layout');

    $denied = $process->invoke('broadcast.operation', ['name' => 'test-connection', 'settings' => [['key' => 'server_url', 'value' => ['tag' => 'text', 'value' => 'https://denied.test']], ['key' => 'credential_name', 'value' => ['tag' => 'text', 'value' => 'plex-api-token']]], 'payload' => []], $capabilities);
    plexAssert(isset($denied['error']), 'undeclared Plex destination was not denied');
    $malformed = true;
    $invalidXml = $process->invoke('broadcast.operation', ['name' => 'test-connection', 'settings' => array_map(static fn(Sdk\Setting $setting): array => ['key' => $setting->key, 'value' => $setting->value->toWire()], $settings), 'payload' => []], $capabilities);
    plexAssert(isset($invalidXml['error']), 'malformed Plex XML was not rejected');
    $process->close();
    $invocation->close();
    echo "M9 native Plex lifecycle: PASS\n";
} catch (Throwable $exception) {
    if ($process !== null) {
        fwrite(STDERR, "native plugin stderr:\n" . $process->stderr() . "\n");
    }
    throw $exception;
} finally {
    if ($failureProcess !== null) {
        $failureProcess->close();
    }
    if ($invocation !== null) {
        $invocation->close();
    }
    plexRemove($root);
}
