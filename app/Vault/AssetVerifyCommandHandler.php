<?php

declare(strict_types=1);

namespace App\Vault;

use App\Commands\CommandHandler;
use App\Commands\CommandId;
use App\Commands\CommandRecord;
use App\Commands\CommandRepository;
use App\Commands\CommandType;
use App\Commands\InvalidCommandPayload;
use App\Http\Api\ApiJson;
use App\Jobs\JobIntent;
use App\Jobs\JobRepository;
use App\Support\PrefixedUlid;

final readonly class AssetVerifyCommandHandler implements CommandHandler
{
    public function __construct(
        private AssetRepository $assets,
        private CommandRepository $commands,
        private JobRepository $jobs,
    ) {}

    public function type(): CommandType
    {
        return CommandType::AssetVerify;
    }

    public function validate(array $options): void
    {
        $assetId = trim(ApiJson::string($options['assetId'] ?? $options['asset_id'] ?? null));

        if ($assetId === '') {
            throw InvalidCommandPayload::withErrors(['asset_id is required.']);
        }

        if (! AssetId::isValid($assetId) || $this->assets->find(AssetId::parse($assetId)) === null) {
            throw InvalidCommandPayload::withErrors(['Asset not found.']);
        }
    }

    public function createJobs(CommandRecord $command, array $options): array
    {
        $commandId = CommandId::fromPrimaryKey($command->id);
        $payload = [
            'asset_id' => trim(ApiJson::string($options['assetId'] ?? $options['asset_id'] ?? null)),
        ];
        $command->options = $payload;
        $command->targetType = 'asset';
        $command->targetId = $payload['asset_id'];
        $this->commands->save($command);

        return [
            $this->jobs->create(
                intent: JobIntent::VerifyVault,
                commandId: $commandId,
                entityType: 'asset',
                entityId: PrefixedUlid::parse($payload['asset_id']),
                payload: $payload,
            ),
        ];
    }

    public function extras(CommandRecord $command, array $options): array
    {
        return [];
    }
}
