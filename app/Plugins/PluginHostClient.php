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
