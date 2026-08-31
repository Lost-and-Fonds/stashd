<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Commands\CommandHandler;
use App\Commands\CommandId;
use App\Commands\CommandRecord;
use App\Commands\CommandRepository;
use App\Commands\CommandType;
use App\Commands\InvalidCommandPayload;
use App\Http\Api\ApiJson;
use App\Config\StashdConfig;
use App\Jobs\JobIntent;
use App\Jobs\JobRepository;
use App\Support\PrefixedUlid;

final readonly class StashPreflightCommandHandler implements CommandHandler
{
    public function __construct(
        private JobRepository $jobs,
        private CommandRepository $commands,
        private StashdConfig $config,
    ) {}

    public function type(): CommandType
    {
        return CommandType::StashPreflight;
    }

    public function validate(array $options): void
    {
        $sourceUri = trim(ApiJson::string($options['sourceUri'] ?? $options['source_uri'] ?? null));

        if ($sourceUri === '') {
            throw InvalidCommandPayload::withErrors(['source_uri is required.']);
        }
    }

    public function createJobs(CommandRecord $command, array $options): array
    {
        $commandId = CommandId::fromPrimaryKey($command->id);
        $payload = $this->normalizedPayload($command, $options);

        $command->options = $payload;
        $this->commands->save($command);

        $job = $this->jobs->create(
            intent: JobIntent::Preflight,
            commandId: $commandId,
            entityType: 'preflight',
            entityId: PrefixedUlid::parse($commandId->toString()),
            payload: $payload,
        );

        return [$job];
    }

    public function extras(CommandRecord $command, array $options): array
    {
        $commandId = (string) $command->id;

        return [
            'review_url' => rtrim($this->config->publicUrl, '/') . '/api/v1/stashes/preflight/' . $commandId . '/review',
        ];
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function normalizedPayload(CommandRecord $command, array $options): array
    {
        $sourceUri = trim(ApiJson::string($options['sourceUri'] ?? $options['source_uri'] ?? null));
        $sourceTitle = $options['sourceTitle'] ?? $options['source_title'] ?? null;
        $sourceTitle = is_string($sourceTitle) && $sourceTitle !== '' ? $sourceTitle : null;
        $originRaw = $options['origin'] ?? null;
        $origin = PreflightOrigin::tryFrom(is_string($originRaw) ? $originRaw : '') ?? PreflightOrigin::Api;

        return [
            'source_uri' => $sourceUri,
            'source_title' => $sourceTitle,
            'origin' => $origin->value,
            'command_id' => (string) $command->id,
        ];
    }
}
