<?php

declare(strict_types=1);

namespace App\Vault;

use App\Commands\CommandHandler;
use App\Commands\CommandId;
use App\Commands\CommandRecord;
use App\Commands\CommandRepository;
use App\Commands\CommandType;
use App\Jobs\JobIntent;
use App\Jobs\JobRepository;
use App\Support\PrefixedUlid;

final readonly class SystemVerifyVaultCommandHandler implements CommandHandler
{
    public function __construct(
        private CommandRepository $commands,
        private JobRepository $jobs,
    ) {}

    public function type(): CommandType
    {
        return CommandType::SystemVerifyVault;
    }

    public function validate(array $options): void {}

    public function createJobs(CommandRecord $command, array $options): array
    {
        $commandId = CommandId::fromPrimaryKey($command->id);
        $payload = ['scope' => 'vault'];
        $command->options = $payload;
        $this->commands->save($command);

        return [
            $this->jobs->create(
                intent: JobIntent::VerifyVault,
                commandId: $commandId,
                entityType: 'vault',
                entityId: PrefixedUlid::parse($commandId->toString()),
                payload: $payload,
            ),
        ];
    }

    public function extras(CommandRecord $command, array $options): array
    {
        return [];
    }
}
