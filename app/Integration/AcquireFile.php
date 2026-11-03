<?php

declare(strict_types=1);

namespace App\Integration;

use App\Operations\Job;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
use Tempest\Validation\Rules\MatchesRegEx;

/**
 * Preserve one discovery's source, independently of the run's other files.
 */
final readonly class AcquireFile implements Job
{
    /**
     * Retain exact persisted run/source identity for durable serialization and replay.
     */
    public function __construct(
        public string $runId,
        public string $sourceId,
    ) {
        if (! Uuid::isValid($runId) || ! new MatchesRegEx('/^[a-f0-9]{64}$/D')->isValid($sourceId)) {
            throw new InvalidArgumentException('A discovery run UUID and Filesystem source ID are required.');
        }
    }

    /**
     * Coalesce only the same source observation within the same discovery run.
     */
    public function key(): string
    {
        return hash('sha256', $this->runId . ':' . $this->sourceId);
    }
}
