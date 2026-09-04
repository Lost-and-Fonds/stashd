<?php

declare(strict_types=1);

namespace App\Jobs;

use RuntimeException;

final readonly class JobMessageHandler
{
    public function __construct(
        private JobRepository $jobs,
        private JobDefinitionRegistry $definitions,
        private \Tempest\Container\Container $container,
        private JobProgressReporter $progress,
    ) {}

    public function __invoke(JobMessage $message): void
    {
        $job = $this->jobs->find(JobId::parse($message->jobId))
            ?? throw new RuntimeException("Queued job not found: {$message->jobId}");

        $definition = $this->definitions->get($message->type);
        $handler = $this->container->get($definition->handler);

        if (! $handler instanceof JobHandler) {
            throw new RuntimeException('Invalid job handler registered for ' . $message->type);
        }

        $handler->handle($job, $this->progress);
    }
}
