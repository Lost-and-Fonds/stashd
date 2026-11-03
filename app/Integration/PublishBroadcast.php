<?php

declare(strict_types=1);

namespace App\Integration;

use App\Operations\Job;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Execute one persisted Integration run; retries retain its identity.
 */
final readonly class PublishBroadcast implements Job
{
    /**
     * Retain validated run identity for serialization.
     */
    public function __construct(
        public string $runId,
    ) {
        if (! Uuid::isValid($runId)) {
            throw new InvalidArgumentException('An Integration run UUID is required.');
        }
    }

    /**
     * Coalesce requests only for this exact saved run.
     */
    public function key(): string
    {
        return $this->runId;
    }
}
