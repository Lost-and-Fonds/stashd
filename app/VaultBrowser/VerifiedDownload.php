<?php

declare(strict_types=1);

namespace App\VaultBrowser;

use Tempest\Http\IsResponse;
use Tempest\Http\Response;

/** Owns a verified private temporary file until response transmission completes. */
final class VerifiedDownload implements Response
{
    use IsResponse;

    /** @param resource $stream */
    public function __construct(
        public mixed $stream,
        string $filename,
        public readonly int $size,
    ) {
        $this->addHeaders([
            'Content-Disposition' => "attachment; filename=\"archived-file\"; filename*=UTF-8''" . rawurlencode($filename),
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $size,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
