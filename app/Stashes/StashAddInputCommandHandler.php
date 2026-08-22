<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Commands\CommandHandler;
use App\Commands\CommandId;
use App\Commands\CommandRecord;
use App\Commands\CommandRepository;
use App\Commands\CommandState;
use App\Commands\CommandType;
use App\Commands\InvalidCommandPayload;
use App\Jobs\JobIntent;
use App\Jobs\JobRepository;
use App\Support\PrefixedUlid;

final readonly class StashAddInputCommandHandler implements CommandHandler
{
    public function __construct(
        private CommandRepository $commands,
        private JobRepository $jobs,
        private StashRepository $stashes,
    ) {}

    public function type(): CommandType
    {
        return CommandType::StashAddInput;
    }

    public function validate(array $options): void
    {
        $stashId = trim((string) ($options['stashId'] ?? $options['stash_id'] ?? ''));

        if ($stashId === '') {
            throw InvalidCommandPayload::withErrors(['stash_id is required.']);
        }

        if (! StashId::isValid($stashId) || $this->stashes->find(StashId::parse($stashId)) === null) {
            throw InvalidCommandPayload::withErrors(['Stash not found.']);
        }

        $preflightCommandId = trim((string) ($options['preflightCommandId'] ?? $options['preflight_command_id'] ?? ''));

        if ($preflightCommandId === '') {
            throw InvalidCommandPayload::withErrors(['preflight_command_id is required.']);
        }

        $command = CommandId::isValid($preflightCommandId) ? $this->commands->find(CommandId::parse($preflightCommandId)) : null;

        if ($command === null || $command->type !== CommandType::StashPreflight) {
            throw InvalidCommandPayload::withErrors(['Preflight command not found.']);
        }

        if ($command->state !== CommandState::Completed || $command->result === null) {
            throw InvalidCommandPayload::withErrors(['Preflight command must be completed with stored results.']);
        }

        $result = $command->result;

        if (trim((string) ($result['source_uri'] ?? '')) === '') {
            throw InvalidCommandPayload::withErrors(['Preflight result is missing its resolved source.']);
        }

        $rawOptions = is_array($options['options'] ?? null) ? $options['options'] : [];
        $inputOptions = StashInputOptions::fromArray($rawOptions);
        $errors = [];

        foreach ([$inputOptions?->titleRegexInclude, $inputOptions?->titleRegexExclude] as $pattern) {
            if ($pattern !== null && ! StashInputOptions::isValidTitleRegex($pattern)) {
                $errors[] = "Invalid title filter pattern: {$pattern}";
            }
        }

        if ($errors !== []) {
            throw InvalidCommandPayload::withErrors($errors);
        }
    }

    public function createJobs(CommandRecord $command, array $options): array
    {
        $stashId = trim((string) ($options['stashId'] ?? $options['stash_id'] ?? ''));
        $preflightCommandId = trim((string) ($options['preflightCommandId'] ?? $options['preflight_command_id'] ?? ''));
        $commandId = CommandId::fromPrimaryKey($command->id);
        $payload = [
            'stash_id' => $stashId,
            'preflight_command_id' => $preflightCommandId,
            'options' => is_array($options['options'] ?? null) ? $options['options'] : [],
        ];

        $command->options = $payload;
        $command->targetType = 'stash';
        $command->targetId = $stashId;
        $this->commands->save($command);

        return [
            $this->jobs->create(
                intent: JobIntent::AddInput,
                commandId: $commandId,
                entityType: 'stash',
                entityId: PrefixedUlid::parse($stashId),
                payload: $payload,
            ),
        ];
    }

    public function extras(CommandRecord $command, array $options): array
    {
        return [];
    }
}
