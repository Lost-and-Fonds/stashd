<?php

declare(strict_types=1);

namespace App\Database;

use App\Commands\CommandState;
use App\Commands\CommandType;
use App\Jobs\JobIntent;
use App\Jobs\JobState;
use App\System\Storage\StorageCheckState;
use App\System\Storage\StorageCheckType;
use App\System\Storage\StorageLocationKey;
use App\System\Storage\StorageLocationState;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\QueryStatements\DatabaseIntegerSize;
use Tempest\Database\QueryStatements\OnDelete;

final class CreateFoundationSchema implements MigratesUp
{
    use MigrationSchemaHelpers;

    public string $name = '2026_06_16_create_foundation_schema';

    public function up(): QueryStatement
    {
        return new CompoundStatement(...$this->tablesWithIndexes(
            $this->storageLocations(),
            $this->storageChecks(),
            $this->commands(),
            $this->jobs(),
            $this->settings(),
        ));
    }

    private function storageLocations(): CreateTableStatement
    {
        return $this->prefixedIdTable('storage_locations')
            ->enum('key', StorageLocationKey::class)
            ->enum('role', StorageLocationKey::class)
            ->string('label')
            ->text('path')
            ->enum('state', StorageLocationState::class, default: StorageLocationState::Missing)
            ->boolean('readable', default: false)
            ->boolean('writable', default: false)
            // BIGINT: byte counts overflow PostgreSQL's 32-bit INTEGER.
            ->integer('freeBytes', nullable: true, size: DatabaseIntegerSize::BIG)
            ->integer('totalBytes', nullable: true, size: DatabaseIntegerSize::BIG)
            ->string('filesystemId', nullable: true)
            ->boolean('supportsHardlinks', default: false)
            ->boolean('supportsSymlinks', default: false)
            ->datetime('lastCheckedAt', nullable: true)
            ->text('lastError', nullable: true);
    }

    private function storageChecks(): CreateTableStatement
    {
        return $this->prefixedIdTable('storage_checks')
            ->raw($this->fkColumn('storageLocationId', 40, 'storage_locations', OnDelete::CASCADE))
            ->enum('checkType', StorageCheckType::class)
            ->enum('state', StorageCheckState::class)
            ->text('message', nullable: true)
            ->text('detailsJson', nullable: true);
    }

    private function commands(): CreateTableStatement
    {
        return $this->prefixedIdTable('commands')
            ->enum('type', CommandType::class)
            ->enum('state', CommandState::class, default: CommandState::Accepted)
            ->string('targetType', nullable: true)
            ->string('targetId', length: 40, nullable: true)
            ->text('optionsJson', nullable: true)
            ->string('createdByUserId', length: 40, nullable: true);
    }

    private function jobs(): CreateTableStatement
    {
        return $this->prefixedIdTable('jobs')
            ->raw($this->fkColumn('commandId', 40, 'commands', OnDelete::SET_NULL, nullable: true))
            ->enum('intent', JobIntent::class)
            ->string('entityType', nullable: true)
            ->string('entityId', length: 40, nullable: true)
            ->enum('state', JobState::class, default: JobState::Pending)
            ->integer('priority', default: 100)
            ->integer('attempts', default: 0)
            ->integer('maxAttempts', default: 3)
            ->datetime('scheduledAt', nullable: true)
            ->datetime('startedAt', nullable: true)
            ->datetime('finishedAt', nullable: true)
            ->datetime('heartbeatAt', nullable: true)
            ->integer('progressCurrent', nullable: true)
            ->integer('progressTotal', nullable: true)
            ->float('progressPercent', nullable: true)
            ->string('progressLabel', nullable: true)
            ->float('progressRate', nullable: true)
            ->integer('progressEtaSeconds', nullable: true)
            ->text('lastError', nullable: true)
            ->text('payloadJson', nullable: true);
    }

    private function settings(): CreateTableStatement
    {
        return new CreateTableStatement('settings')
            ->string('key')
            ->text('valueJson', nullable: true)
            ->datetime('updatedAt', current: true)
            ->raw('PRIMARY KEY (`key`)');
    }
}
