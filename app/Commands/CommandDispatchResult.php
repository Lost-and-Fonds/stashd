<?php

declare(strict_types=1);

namespace App\Commands;

use App\Jobs\JobRecord;

final readonly class CommandDispatchResult
{
    /**
     * @param list<JobRecord> $jobs
     * @param array<string, mixed> $extras
     */
    public function __construct(
        public CommandRecord $command,
        public array $jobs,
        public array $extras = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'command_id' => (string) $this->command->id,
            'command_state' => $this->command->state->value,
            'command_type' => $this->command->type->value,
            'job_ids' => array_map(static fn(JobRecord $job): string => (string) $job->id, $this->jobs),
            'jobs' => array_map(static fn(JobRecord $job): array => [
                'id' => (string) $job->id,
                'intent' => $job->intent->value,
                'state' => $job->state->value,
            ], $this->jobs),
            ...$this->extras,
        ];
    }
}
