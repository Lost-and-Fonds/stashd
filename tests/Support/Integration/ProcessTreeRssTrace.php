<?php

declare(strict_types=1);

namespace Tests\Support\Integration;

use RuntimeException;

/**
 * Read only new complete samples from the owned, append-only process-tree RSS trace.
 * Final phase peaks are still calculated independently from the complete trace.
 */
final class ProcessTreeRssTrace
{
    private int $offset = 0;
    private ?int $maximum = null;

    /**
     * Bind the sampler's private trace without opening it before the sampler creates it.
     */
    public function __construct(
        private string $path,
    ) {}

    /**
     * Extend the observed maximum without reparsing history or consuming a partial line.
     * A missing trace has no samples yet; malformed complete JSON fails observation.
     */
    public function peak(): ?int
    {
        if (! is_file($this->path)) {
            return $this->maximum;
        }

        $stream = fopen($this->path, 'r');
        if ($stream === false) {
            throw new RuntimeException('Could not read the process-tree RSS trace.');
        }

        try {
            if (fseek($stream, $this->offset) !== 0) {
                throw new RuntimeException('Could not resume the process-tree RSS trace.');
            }

            while (($line = fgets($stream)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    break;
                }

                $point = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (is_int($point['rss_kib'] ?? null)) {
                    $this->maximum = max($this->maximum ?? 0, $point['rss_kib']);
                }

                $position = ftell($stream);
                if ($position === false) {
                    throw new RuntimeException('Could not save the process-tree RSS trace position.');
                }

                $this->offset = $position;
            }
        } finally {
            fclose($stream);
        }

        return $this->maximum;
    }
}
