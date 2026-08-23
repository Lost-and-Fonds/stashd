<?php

declare(strict_types=1);

namespace Stashd\PluginSdk\Native;

use Stashd\PluginSdk\BroadcastPlugin;
use Stashd\PluginSdk\FinalizationRequest;
use Stashd\PluginSdk\Item;
use Stashd\PluginSdk\ItemResource;
use Stashd\PluginSdk\OperationRequest;
use Stashd\PluginSdk\PluginContext;
use Stashd\PluginSdk\Preparation;
use Stashd\PluginSdk\Publication;
use Stashd\PluginSdk\PublishRequest;
use Stashd\PluginSdk\Setting;
use Stashd\PluginSdk\Source;
use Stashd\PluginSdk\StagingArea;
use Stashd\PluginSdk\WireMapper;
use Throwable;

final class NativePluginServer
{
    public function __construct(private BroadcastPlugin $broadcast) {}

    public function run(): never
    {
        NativeFrameCodec::write(STDOUT, ['protocol' => 1, 'id' => 'sdk-hello', 'kind' => 'request', 'method' => 'hello', 'params' => []]);
        NativeFrameCodec::read(STDIN, 30.0);
        while (($message = NativeFrameCodec::read(STDIN, 3600.0)) !== null) {
            $id = is_string($message['id'] ?? null) ? $message['id'] : '';
            try {
                $result = $this->dispatch((string) ($message['method'] ?? ''), is_array($message['params'] ?? null) ? $message['params'] : []);
                NativeFrameCodec::write(STDOUT, ['protocol' => 1, 'id' => $id, 'kind' => 'response', 'result' => $result]);
            } catch (Throwable $exception) {
                NativeFrameCodec::write(STDOUT, ['protocol' => 1, 'id' => $id, 'kind' => 'response', 'error' => ['code' => 'plugin-failure', 'message' => $exception->getMessage(), 'retryable' => false]]);
            }
        }
        exit(0);
    }

    private function dispatch(string $method, array $params): array
    {
        $context = $this->context();
        return match ($method) {
            'broadcast.prepare' => WireMapper::preparation($this->broadcast->prepare($this->publishRequest($params, $context->staging))),
            'broadcast.publish' => WireMapper::publication($this->broadcast->publish($this->publishRequest($params, $context->staging))),
            'broadcast.finalize' => WireMapper::publication($this->broadcast->finalize(new FinalizationRequest($this->publishRequest($params['request'] ?? $params, $context->staging), $this->publication($params['publication'] ?? [])), $context)),
            'broadcast.operation' => WireMapper::operationResult($this->broadcast->operation($this->operationRequest($params), $context)),
            default => throw new \RuntimeException('unknown plugin method: ' . $method),
        };
    }

    private function context(): PluginContext
    {
        $call = function (string $method, array $params): array {
            static $next = 1;
            $id = 'sdk-' . $next++;
            NativeFrameCodec::write(STDOUT, ['protocol' => 1, 'id' => $id, 'kind' => 'request', 'method' => $method, 'params' => $params]);
            while (($message = NativeFrameCodec::read(STDIN, 30.0)) !== null) {
                if (($message['id'] ?? null) !== $id) {
                    continue;
                }
                if (isset($message['error'])) {
                    throw new \RuntimeException((string) (($message['error']['message'] ?? null) ?: 'capability failed'));
                }

                return is_array($message['result'] ?? null) ? $message['result'] : [];
            }
            throw new \RuntimeException('host closed capability channel');
        };

        return new PluginContext(new NativeLogger($call), new NativeProgressReporter($call), new NativeHttpClient($call), new NativeStagingArea($call));
    }

    /** @param array<string,mixed> $data */
    private function publishRequest(array $data, ?StagingArea $staging = null): PublishRequest
    {
        return WireMapper::publishRequestFromWire($data, $staging);
    }

    /** @param array<string,mixed> $data */
    private function operationRequest(array $data): OperationRequest
    {
        return WireMapper::operationRequestFromWire($data);
    }

    /** @param array<string,mixed> $data */
    private function publication(array $data): Publication
    {
        return WireMapper::publicationFromWire($data);
    }
}
