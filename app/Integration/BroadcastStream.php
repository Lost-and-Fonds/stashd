<?php

declare(strict_types=1);

namespace App\Integration;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Expose a bounded forward-only read without leaking a Vault path or raw handle.
 */
final class BroadcastStream
{
    /**
     * Own the verified stream and recheck invocation authority before every read.
     */
    public function __construct(
        private mixed $stream,
        private int $remaining,
        private Closure $authorize,
    ) {}

    /**
     * Return a nonempty chunk or sticky EOF; premature storage EOF is a failure.
     */
    public function read(int $maximum = 65_536): ?string
    {
        ($this->authorize)();
        if ($maximum < 1 || $maximum > 1_048_576) {
            throw new InvalidArgumentException('Read between 1 and 1048576 bytes.');
        }

        if (! is_resource($this->stream)) {
            throw new RuntimeException('Stream is closed.');
        }

        if ($this->remaining === 0) {
            return null;
        }

        $bytes = fread($this->stream, min($maximum, $this->remaining));
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Preserved stream ended before its verified extent.');
        }

        $this->remaining -= strlen($bytes);

        return $bytes;
    }

    /**
     * Release the underlying handle once; repeated closure is harmless.
     */
    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }

    /**
     * Release abandoned readers even when the publisher exits through an exception.
     */
    public function __destruct()
    {
        $this->close();
    }
}
