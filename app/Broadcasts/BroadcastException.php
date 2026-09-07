<?php

declare(strict_types=1);

namespace App\Broadcasts;

use RuntimeException;

final class BroadcastException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        ?\Throwable $previous = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function withCode(string $errorCode, string $message, ?\Throwable $previous = null, bool $retryable = false): self
    {
        return new self($message, $errorCode, $previous, $retryable);
    }
}
