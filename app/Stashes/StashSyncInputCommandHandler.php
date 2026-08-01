<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Commands\CommandHandler;
use App\Commands\CommandId;
use App\Commands\CommandRecord;
use App\Commands\CommandRepository;
use App\Commands\CommandType;
use App\Commands\InvalidCommandPayload;
use App\Jobs\JobIntent;
use App\Jobs\JobRepository;
use App\Support\PrefixedUlid;

final readonly class StashSyncInputCommandHandler implements CommandHandler
{
    public function __construct(
        private CommandRepository $commands,
        private JobRepository $jobs,
        private StashInputRepository $stashInputs,
    ) {
    }

    public function type(): CommandType
    {
        return CommandType::StashSyncInput;
    }

    public function validate(array $options): void
    {
        $stashInputId = $this->stashInputId($options);

        if ($stashInputId === '') {
            throw InvalidCommandPayload::withErrors(['stash_input_id is required.']);
        }

        if (! StashInputId::isValid($stashInputId) || $this->stashInputs->find(StashInputId::parse($stashInputId)) === null) {
            throw InvalidCommandPayload::withErrors(['Stash input not found.']);
        }
    }

    public function createJobs(CommandRecord $command, array $options): array
    {
        $stashInputId = $this->stashInputId($options);
        $input = $this->stashInputs->find(StashInputId::parse($stashInputId));

        if ($input === null) {
            throw InvalidCommandPayload::withErrors(['Stash input not found.']);
        }

        $stashId = $input->stashId->toString();
        $payload = ['stash_input_id' => $stashInputId];

        $command->options = $payload;
        $command->targetType = 'stash';
        $command->targetId = $stashId;
        $this->commands->save($command);

        // Two syncs of one input would both discover, both realign positions
        // and both trigger broadcast rebuilds. The scheduler and the manual
        // check both dispatch through here, so this one guard covers both:
        // a double-click, or a click while the hourly check is still queued.
        $entityId = PrefixedUlid::parse($stashInputId);

        if ($this->jobs->hasPendingOrProcessing(JobIntent::SyncInput, $entityId)) {
            return [];
        }

        return [
            $this->jobs->create(
                intent: JobIntent::SyncInput,
                commandId: CommandId::fromPrimaryKey($command->id),
                entityType: 'stash_input',
                entityId: $entityId,
                payload: $payload,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function extras(CommandRecord $command, array $options): array
    {
        return [];
    }

    /** @param array<string, mixed> $options */
    private function stashInputId(array $options): string
    {
        $raw = $options['stashInputId'] ?? $options['stash_input_id'] ?? null;

        return is_string($raw) ? trim($raw) : '';
    }
}
