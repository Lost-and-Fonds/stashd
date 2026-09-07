<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Runner;

use RuntimeException;

final class PluginInvocationFailure extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    /** @param mixed $error */
    public static function fromWire(mixed $error): self
    {
        $error = is_array($error) ? $error : [];
        $value = is_array($error['value'] ?? null) ? $error['value'] : [];
        $code = is_string($error['tag'] ?? null) ? $error['tag'] : (is_string($error['code'] ?? null) ? $error['code'] : 'failed');
        $message = is_string($value['message'] ?? null) ? $value['message'] : (is_string($error['message'] ?? null) ? $error['message'] : 'Plugin invocation failed.');
        $retryable = is_bool($value['retryable'] ?? null) ? $value['retryable'] : (is_bool($error['retryable'] ?? null) ? $error['retryable'] : false);

        return new self($code, $message, $retryable);
    }
}
