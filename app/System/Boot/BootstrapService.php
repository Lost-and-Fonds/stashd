<?php

declare(strict_types=1);

namespace App\System\Boot;

use App\Commands\CommandId;
use App\Commands\CommandRepository;
use App\Commands\CommandType;
use App\Jobs\JobIntent;
use App\Jobs\JobRepository;
use App\System\Storage\StorageCapabilityChecker;
use App\System\Storage\StorageRootService;
use Tempest\Database\Config\DatabaseConfig;

final readonly class BootstrapService
{
    public function __construct(
        private StorageRootService $storageRoots,
        private StorageCapabilityChecker $storageChecks,
        private SqliteConfigurator $sqlite,
        private MigrationRunner $migrations,
        private CommandRepository $commands,
        private JobRepository $jobs,
    ) {
    }

    /** @return array{directories_created: list<string>, command_id: string, job_id: string} */
    public function boot(DatabaseConfig $databaseConfig): array
    {
        $created = $this->storageRoots->ensureDirectories();
        $this->sqlite->configure($databaseConfig);
        $this->sqlite->enableWriteAheadLogging($databaseConfig);
        $this->migrations->run($databaseConfig);
        $this->storageChecks->checkAll();

        $command = $this->commands->create(CommandType::SystemBoot);
        $job = $this->jobs->create(
            intent: JobIntent::Boot,
            commandId: CommandId::fromPrimaryKey($command->id),
            entityType: 'system',
            payload: ['phase' => 'boot'],
        );

        return [
            'directories_created' => $created,
            'command_id' => (string) $command->id,
            'job_id' => (string) $job->id,
        ];
    }
}
