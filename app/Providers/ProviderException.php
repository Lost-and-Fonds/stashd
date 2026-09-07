<?php

declare(strict_types=1);

namespace App\Providers;

use RuntimeException;

class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'provider_error',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function withUnsupportedUrl(string $url, string $message): self
    {
        return new self("{$message} ({$url})", 'unsupported_provider_url');
    }
}
