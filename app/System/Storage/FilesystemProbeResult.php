<?php

declare(strict_types=1);

namespace App\System\Storage;

final readonly class FilesystemProbeResult
{
    public function __construct(
        public bool $ok,
        public string $message,
        public ?string $errorCode = null,
    ) {}

    public static function ok(string $message = 'OK'): self
    {
        return new self(ok: true, message: $message);
    }

    public static function failed(string $message, ?string $errorCode = null): self
    {
        return new self(ok: false, message: $message, errorCode: $errorCode);
    }
}
