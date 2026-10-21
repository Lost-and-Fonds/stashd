<?php

declare(strict_types=1);

namespace App\VaultBrowser;

use Tempest\Http\IsResponse;
use Tempest\Http\Response;

/**
 * Own a verified private temporary file until response transmission completes.
 *
 * The browser sender closes it explicitly; destruction provides fallback cleanup.
 * This response assumes its bytes and size were verified before construction.
 */
final class VerifiedDownload implements Response
{
    use IsResponse;

    /**
     * Take ownership of a verified stream and describe it as an attachment.
     *
     * The sender rewinds the stream before transmission. The supplied filename
     * is encoded for the header; the constructor does not reverify content.
     *
     * @param resource $stream
     */
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

    /**
     * Close the owned stream if still open; repeated cleanup calls are safe.
     */
    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /**
     * Release any remaining download stream when the response is destroyed.
     */
    public function __destruct()
    {
        $this->close();
    }
}
