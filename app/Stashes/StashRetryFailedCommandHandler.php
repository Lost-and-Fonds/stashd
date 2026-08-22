<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Commands\CommandHandler;
use App\Commands\CommandId;
use App\Commands\CommandRecord;
use App\Commands\CommandRepository;
use App\Commands\CommandType;
use App\Commands\InvalidCommandPayload;
use App\Jobs\Handlers\RetryFailedDownloadsJobHandler;
use App\Jobs\JobIntent;
use App\Jobs\JobRepository;
use App\Support\PrefixedUlid;

/**
 * Looks up every failed item in the stash server-side (see
 * {@see RetryFailedDownloadsJobHandler}) rather than
 * retrying whatever the caller happened to have loaded client-side --
 * correct regardless of pagination.
 */
final readonly class StashRetryFailedCommandHandler implements CommandHandler
{
    public function __construct(
        private CommandRepository $commands,
        private JobRepository $jobs,
        private StashRepository $stashes,
    ) {}

    public function type(): CommandType
    {
        return CommandType::StashRetryFailed;
    }

    public function validate(array $options): void
    {
        $stashId = $this->stashIdFrom($options);

        if ($stashId === '') {
            throw InvalidCommandPayload::withErrors(['stash_id is required.']);
        }

        if (! StashId::isValid($stashId) || $this->stashes->find(StashId::parse($stashId)) === null) {
            throw InvalidCommandPayload::withErrors(['Stash not found.']);
        }
    }

    public function createJobs(CommandRecord $command, array $options): array
    {
        $stashId = $this->stashIdFrom($options);
        $commandId = CommandId::fromPrimaryKey($command->id);
        $payload = ['stash_id' => $stashId];

        $command->options = $payload;
        $command->targetType = 'stash';
        $command->targetId = $stashId;
        $this->commands->save($command);

        return [
            $this->jobs->create(
                intent: JobIntent::RetryFailedDownloads,
                commandId: $commandId,
                entityType: 'stash',
                entityId: PrefixedUlid::parse($stashId),
                payload: $payload,
            ),
        ];
    }

    /** @param array<string, mixed> $options */
    public function extras(CommandRecord $command, array $options): array
    {
        return [];
    }

    /** @param array<string, mixed> $options */
    private function stashIdFrom(array $options): string
    {
        $raw = $options['stashId'] ?? $options['stash_id'] ?? '';

        return is_string($raw) ? trim($raw) : '';
    }
}
