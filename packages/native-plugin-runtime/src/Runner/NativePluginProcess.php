<?php

declare(strict_types=1);

namespace Stashd\NativeRuntime\Runner;

use RuntimeException;
use Stashd\NativeRuntime\Rpc\FrameCodec;
use Stashd\NativeRuntime\Rpc\FrameProtocolError;
use Stashd\NativeRuntime\Sandbox\SandboxPolicy;
use Throwable;

final class NativePluginProcess
{
    /** @var resource */
    private $process;
    /** @var array<int, resource> */
    private array $pipes = [];
    private int $nextId = 1;
    private bool $closed = false;

    public function __construct(
        string $packageRoot,
        string $stagingRoot,
        string $entrypoint = 'plugin.php',
        ?SandboxPolicy $policy = null,
    ) {
        $command = ($policy ?? new SandboxPolicy())->command($packageRoot, $stagingRoot, $entrypoint);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('native plugin process could not start');
        }
        $this->process = $process;
        $this->handshake();
    }

    /**
     * @param array<string, mixed> $params
     * @param callable(array<string, mixed>):array<string, mixed> $capabilityHandler
     * @return array<string, mixed>
     */
    public function invoke(string $method, array $params, callable $capabilityHandler, float $timeout = 30.0): array
    {
        $id = 'host-' . $this->nextId++;
        FrameCodec::write($this->pipes[0], ['protocol' => 1, 'id' => $id, 'kind' => 'request', 'method' => $method, 'params' => $params]);
        $deadline = microtime(true) + $timeout;
        while (true) {
            $message = FrameCodec::read($this->pipes[1], max(0.0, $deadline - microtime(true)));
            if ($message === null) {
                throw new RuntimeException('native plugin closed IPC before responding');
            }
            if (($message['kind'] ?? null) === 'request') {
                $responseId = $message['id'] ?? null;
                if (!is_string($responseId)) {
                    throw new FrameProtocolError('native plugin capability request has no ID');
                }
                try {
                    $result = $capabilityHandler($message);
                    FrameCodec::write($this->pipes[0], ['protocol' => 1, 'id' => $responseId, 'kind' => 'response', 'result' => $result]);
                } catch (Throwable $exception) {
                    FrameCodec::write($this->pipes[0], ['protocol' => 1, 'id' => $responseId, 'kind' => 'response', 'error' => ['code' => 'capability-denied', 'message' => $exception->getMessage()]]);
                }
                continue;
            }
            if (($message['id'] ?? null) !== $id) {
                throw new FrameProtocolError('native plugin response ID mismatch');
            }
            if (isset($message['error'])) {
                $error = $message['error'];
                if (is_array($error)) {
                    $normalizedError = [];
                    foreach ($error as $key => $value) {
                        if (is_string($key)) {
                            $normalizedError[$key] = $value;
                        }
                    }
                    return ['error' => $normalizedError];
                }
                return ['error' => ['message' => is_string($error) ? $error : 'native plugin returned an error']];
            }
            $result = $message['result'] ?? [];
            if (!is_array($result)) {
                return [];
            }
            $normalizedResult = [];
            foreach ($result as $key => $value) {
                if (is_string($key)) {
                    $normalizedResult[$key] = $value;
                }
            }
            return $normalizedResult;
        }
    }

    public function stderr(): string
    {
        $value = stream_get_contents($this->pipes[2]);
        return is_string($value) ? $value : '';
    }

    public function close(): int
    {
        if ($this->closed) {
            return 0;
        }
        $this->closed = true;
        if (is_resource($this->pipes[0])) {
            fclose($this->pipes[0]);
        }
        return proc_close($this->process);
    }

    public function terminate(): void
    {
        if (!$this->closed) {
            proc_terminate($this->process, 9);
            $this->close();
        }
    }

    private function handshake(): void
    {
        $message = FrameCodec::read($this->pipes[1], 5.0);
        if ($message === null) {
            throw new FrameProtocolError('native plugin produced no handshake: ' . $this->stderr());
        }
        if (($message['method'] ?? null) !== 'hello' || !isset($message['id'])) {
            throw new FrameProtocolError('native plugin handshake is invalid: ' . json_encode($message, JSON_THROW_ON_ERROR));
        }
        FrameCodec::write($this->pipes[0], ['protocol' => 1, 'id' => $message['id'], 'kind' => 'response', 'result' => ['protocol' => 1, 'min' => 1, 'max' => 1]]);
    }
}
