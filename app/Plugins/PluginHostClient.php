<?php

declare(strict_types=1);

namespace App\Plugins;

use JsonException;
use RuntimeException;

final class PluginHostClient
{
    public function __construct(private readonly string $socketPath)
    {
    }

    public function invoke(PluginInvocation $invocation): PluginInvocationResult
    {
        $error = null;
        $socket = stream_socket_client(
            'unix://' . $this->socketPath,
            $errorNumber,
            $error,
            5,
        );

        if (! is_resource($socket)) {
            throw new RuntimeException('Unable to connect to stashd-plugin-host: ' . ($error ?: 'unknown error'));
        }

        $requestId = bin2hex(random_bytes(8));
        $request = [
            'id' => $requestId,
            'op' => 'invoke',
            'component_path' => $invocation->componentPath,
            'asset_path' => $invocation->assetPath,
            'staging_path' => $invocation->stagingPath,
            'operation' => $invocation->operation,
        ];

        try {
            fwrite($socket, json_encode($request, JSON_THROW_ON_ERROR) . "\n");

            /** @var list<array{fraction: float, stage: string}> $progress */
            $progress = [];
            /** @var list<string> $logs */
            $logs = [];
            /** @var array<string, mixed>|null $result */
            $result = null;

            while (($line = fgets($socket)) !== false) {
                $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                if (! is_array($decoded)) {
                    throw new RuntimeException('Plugin host returned a non-object event.');
                }

                /** @var array<string, mixed> $event */
                $event = $decoded;
                $eventId = $event['id'] ?? null;

                if (! is_string($eventId) || $eventId !== $requestId) {
                    throw new RuntimeException('Plugin host returned a mismatched request ID.');
                }

                $eventName = $event['event'] ?? null;

                if (! is_string($eventName)) {
                    throw new RuntimeException('Plugin host returned an event without a name.');
                }

                match ($eventName) {
                    'progress' => $this->appendProgress($progress, $event),
                    'log' => $this->appendLog($logs, $event),
                    'result' => $result = $event,
                    'error' => $this->throwExecutionError($event),
                    default => throw new RuntimeException('Plugin host returned an unknown event.'),
                };

                if ($result !== null) {
                    $sourceBytes = $result['source_bytes'] ?? null;
                    $outputId = $result['output_id'] ?? null;
                    $outputBytes = $result['output_bytes'] ?? null;

                    if (! is_int($sourceBytes) || ! is_string($outputId) || ! is_int($outputBytes)) {
                        throw new RuntimeException('Plugin host returned an invalid result event.');
                    }

                    return new PluginInvocationResult(
                        progress: $progress,
                        logs: $logs,
                        sourceBytes: $sourceBytes,
                        outputId: $outputId,
                        outputBytes: $outputBytes,
                    );
                }
            }
        } catch (JsonException $exception) {
            throw new RuntimeException('Plugin host returned malformed JSON.', previous: $exception);
        } finally {
            fclose($socket);
        }

        throw new RuntimeException('Plugin host closed the IPC connection without a result.');
    }

    /** @param list<PluginHttpGrant>|null $httpGrants */
    public function resolveInput(
        string $componentPath,
        string $source,
        ?string $fixtureDirectory = null,
        ?array $httpGrants = null,
    ): PluginInputResult {
        return $this->invokeInput('input-resolve', $componentPath, $source, null, $fixtureDirectory, httpGrants: $httpGrants);
    }

    /** @param list<PluginHttpGrant>|null $httpGrants
     * @param array<string, bool|string> $options
     */
    public function discoverInput(
        string $componentPath,
        string $inputId,
        ?string $fixtureDirectory = null,
        string $intent = 'refresh',
        ?array $httpGrants = null,
        array $options = [],
    ): PluginInputResult {
        return $this->invokeInput('input-discover', $componentPath, null, $inputId, $fixtureDirectory, $intent, httpGrants: $httpGrants, options: $this->encodeOptions($options));
    }

    /** @param array<string, mixed> $item
     * @param array<string, bool|string> $options
     */
    public function acquireInput(
        string $componentPath,
        array $item,
        string $stagingDirectory,
        ?PluginHelperGrant $helper,
        string $mediaKind = 'video',
        array $options = [],
    ): PluginInputResult {
        return $this->invokeInput(
            'input-acquire',
            $componentPath,
            null,
            null,
            null,
            'refresh',
            null,
            $stagingDirectory,
            $helper,
            $item,
            $mediaKind,
            $this->encodeOptions($options),
        );
    }

    /**
     * @param array<string, mixed> $broadcast
     */
    public function publishBroadcast(
        string $componentPath,
        string $stagingDirectory,
        array $broadcast,
        ?PluginHelperGrant $helper = null,
    ): PluginBroadcastResult {
        return $this->invokeBroadcast('broadcast-publish', 'broadcast_published', $componentPath, $stagingDirectory, $broadcast, $helper);
    }

    /** @param array<string, mixed> $broadcast */
    public function prepareBroadcast(
        string $componentPath,
        string $stagingDirectory,
        array $broadcast,
        ?PluginHelperGrant $helper = null,
    ): PluginBroadcastResult {
        return $this->invokeBroadcast('broadcast-prepare', 'broadcast_prepared', $componentPath, $stagingDirectory, $broadcast, $helper);
    }

    /** @param array<string, mixed> $broadcast */
    private function invokeBroadcast(
        string $operation,
        string $eventName,
        string $componentPath,
        string $stagingDirectory,
        array $broadcast,
        ?PluginHelperGrant $helper,
    ): PluginBroadcastResult {
        $error = null;
        $socket = stream_socket_client('unix://' . $this->socketPath, $errorNumber, $error, 5);
        if (! is_resource($socket)) {
            throw new RuntimeException('Unable to connect to stashd-plugin-host: ' . ($error ?: 'unknown error'));
        }

        $requestId = bin2hex(random_bytes(8));
        $request = [
            'id' => $requestId,
            'op' => $operation,
            'component_path' => $componentPath,
            'staging_dir' => $stagingDirectory,
            'helper_name' => $helper?->name,
            'helper_executable' => $helper?->executable,
            'helper_package_root' => $helper?->packageRoot,
            'broadcast' => $broadcast,
        ];
        $request = array_filter($request, static fn (mixed $value): bool => $value !== null);
        $progress = [];
        $logs = [];

        try {
            fwrite($socket, json_encode($request, JSON_THROW_ON_ERROR) . "\n");
            while (($line = fgets($socket)) !== false) {
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($event) || ($event['id'] ?? null) !== $requestId) {
                    throw new RuntimeException('Plugin host returned an invalid broadcast event.');
                }

                /** @var array<string, mixed> $event */
                if (($event['event'] ?? null) === 'progress') {
                    $progress[] = $this->inputProgress($event);
                    continue;
                }
                if (($event['event'] ?? null) === 'log') {
                    $logs[] = $this->inputString($event['message'] ?? null, 'message');
                    continue;
                }
                if (($event['event'] ?? null) === 'error') {
                    $this->throwExecutionError($event);
                }
                if (($event['event'] ?? null) !== $eventName) {
                    throw new RuntimeException('Plugin host returned an unknown broadcast event.');
                }

                return new PluginBroadcastResult(
                    progress: $progress,
                    logs: $logs,
                    publication: $this->inputObject($event[$eventName === 'broadcast_prepared' ? 'preparation' : 'publication'] ?? null, $eventName),
                );
            }
        } catch (JsonException $exception) {
            throw new RuntimeException('Plugin host returned malformed JSON.', previous: $exception);
        } finally {
            fclose($socket);
        }

        throw new RuntimeException('Plugin host closed the IPC connection without a broadcast result.');
    }

    /**
     * @param array<string, mixed>|null $item
     * @param list<PluginHttpGrant>|null $httpGrants
     * @param list<array{key:string,value:array{kind:string,value:bool|string}}>|null $options
     */
    private function invokeInput(
        string $operation,
        string $componentPath,
        ?string $source,
        ?string $inputId,
        ?string $fixtureDirectory,
        string $intent = 'refresh',
        ?array $httpGrants = null,
        ?string $stagingDirectory = null,
        ?PluginHelperGrant $helper = null,
        ?array $item = null,
        ?string $mediaKind = null,
        ?array $options = null,
    ): PluginInputResult {
        $error = null;
        $socket = stream_socket_client('unix://' . $this->socketPath, $errorNumber, $error, 5);
        if (! is_resource($socket)) {
            throw new RuntimeException('Unable to connect to stashd-plugin-host: ' . ($error ?: 'unknown error'));
        }

        $requestId = bin2hex(random_bytes(8));
        $request = array_filter([
            'id' => $requestId,
            'op' => $operation,
            'component_path' => $componentPath,
            'source' => $source,
            'input_id' => $inputId,
            'fixture_dir' => $fixtureDirectory,
            'intent' => $intent,
            'http_grants' => $httpGrants === null ? null : array_map(
                static fn (PluginHttpGrant $grant): array => [
                    'allowed_prefixes' => $grant->allowedPrefixes,
                    'credential_name' => $grant->credential?->name,
                    'credential_value' => $grant->credential?->value,
                    'credential_parameter' => $grant->credential?->queryParameter,
                ],
                $httpGrants,
            ),
            'staging_dir' => $stagingDirectory,
            'helper_name' => $helper?->name,
            'helper_executable' => $helper?->executable,
            'item' => $item,
            'media_kind' => $mediaKind,
            'options' => $options,
        ], static fn (mixed $value): bool => $value !== null);
        /** @var list<array{fraction: float, stage: string}> $progress */
        $progress = [];
        $logs = [];
        /** @var array<string, mixed>|null $resolved */
        $resolved = null;
        /** @var list<array<string, mixed>>|null $items */
        $items = null;
        /** @var array<string, mixed>|null $acquisition */
        $acquisition = null;

        try {
            fwrite($socket, json_encode($request, JSON_THROW_ON_ERROR) . "\n");
            while (($line = fgets($socket)) !== false) {
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($event) || ($event['id'] ?? null) !== $requestId) {
                    throw new RuntimeException('Plugin host returned an invalid input event.');
                }
                /** @var array<string, mixed> $event */
                match ($event['event'] ?? null) {
                    'progress' => $progress[] = $this->inputProgress($event),
                    'log' => $logs[] = $this->inputString($event['message'] ?? null, 'message'),
                    'input_resolved' => $resolved = $this->inputObject($event['resolved'] ?? null, 'resolved'),
                    'input_discovered' => $items = $this->inputObjects($event['items'] ?? null),
                    'input_acquired' => $acquisition = $this->inputObject($event['acquisition'] ?? null, 'acquisition'),
                    'error' => $this->throwExecutionError($event),
                    default => throw new RuntimeException('Plugin host returned an unknown input event.'),
                };
                if (isset($resolved) || isset($items) || isset($acquisition)) {
                    return new PluginInputResult($progress, $logs, $resolved ?? null, $items ?? null, $acquisition);
                }
            }
        } catch (JsonException $exception) {
            throw new RuntimeException('Plugin host returned malformed JSON.', previous: $exception);
        } finally {
            fclose($socket);
        }

        throw new RuntimeException('Plugin host closed the IPC connection without an input result.');
    }

    /**
     * @param array<string, mixed> $event
     * @return array{fraction: float, stage: string}
     */
    private function inputProgress(array $event): array
    {
        $fraction = $event['fraction'] ?? 0;
        $stage = $event['stage'] ?? '';
        if ((! is_float($fraction) && ! is_int($fraction)) || ! is_string($stage)) {
            throw new RuntimeException('Plugin host returned invalid input progress.');
        }
        return ['fraction' => (float) $fraction, 'stage' => $stage];
    }

    /** @param array<string, mixed> $options
     * @return list<array{key:string,value:array{kind:string,value:bool|string}}>
     */
    private function encodeOptions(array $options): array
    {
        $encoded = [];
        foreach ($options as $key => $value) {
            if (! is_bool($value) && ! is_string($value)) {
                continue;
            }
            $encoded[] = [
                'key' => $key,
                'value' => is_bool($value)
                    ? ['kind' => 'boolean', 'value' => $value]
                    : ['kind' => 'text', 'value' => $value],
            ];
        }

        return $encoded;
    }

    private function inputString(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw new RuntimeException("Plugin host returned invalid input {$field}.");
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private function inputObject(mixed $value, string $field): array
    {
        if (! is_array($value) || array_keys($value) !== array_filter(array_keys($value), 'is_string')) {
            throw new RuntimeException("Plugin host returned invalid input {$field}.");
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return list<array<string, mixed>> */
    private function inputObjects(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('Plugin host returned invalid input items.');
        }
        return array_map(fn (mixed $item): array => $this->inputObject($item, 'item'), $value);
    }

    /**
     * @param list<array{fraction: float, stage: string}> $progress
     * @param array<string, mixed> $event
     */
    private function appendProgress(array &$progress, array $event): void
    {
        $fraction = $event['fraction'] ?? null;
        $stage = $event['stage'] ?? null;

        if ((! is_float($fraction) && ! is_int($fraction)) || ! is_string($stage)) {
            throw new RuntimeException('Plugin host returned an invalid progress event.');
        }

        $progress[] = ['fraction' => (float) $fraction, 'stage' => $stage];
    }

    /**
     * @param list<string> $logs
     * @param array<string, mixed> $event
     */
    private function appendLog(array &$logs, array $event): void
    {
        $message = $event['message'] ?? null;

        if (! is_string($message)) {
            throw new RuntimeException('Plugin host returned an invalid log event.');
        }

        $logs[] = $message;
    }

    /** @param array<string, mixed> $event */
    private function throwExecutionError(array $event): never
    {
        $code = $event['code'] ?? null;
        $message = $event['message'] ?? null;

        if (! is_string($code) || ! is_string($message)) {
            throw new RuntimeException('Plugin host returned an invalid error event.');
        }

        throw new RuntimeException(sprintf('Plugin host execution error [%s]: %s', $code, $message));
    }
}
