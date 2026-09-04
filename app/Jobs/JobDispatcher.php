<?php

declare(strict_types=1);

namespace App\Jobs;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final readonly class JobDispatcher
{
    public function __construct(
        private JobRepository $jobs,
        private JobDefinitionRegistry $definitions,
        private MessageBusInterface $bus,
    ) {}

    /** @param array<string, mixed>|null $payload */
    public function dispatch(
        string $type,
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $stashId = null,
        ?array $payload = null,
        ?string $workload = null,
    ): JobRecord {
        $definition = $this->definitions->get($type);

        if ($workload !== null && $workload !== $definition->workload) {
            throw new \InvalidArgumentException("Workload does not match job type {$type}.");
        }

        $job = $this->jobs->createType($type, $entityType, $entityId, $stashId, $payload);
        $this->bus->dispatch(new JobMessage((string) $job->id, $type, $definition->workload), [new TransportNamesStamp([$definition->workload])]);

        return $job;
    }

}
