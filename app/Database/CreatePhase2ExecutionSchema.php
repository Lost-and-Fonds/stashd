<?php

declare(strict_types=1);

namespace App\Database;

use App\System\Activity\ActivityLevel;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;

final class CreatePhase2ExecutionSchema implements MigratesUp
{
    use MigrationSchemaHelpers;

    public string $name = '2026_06_18_phase2_execution';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement('ALTER TABLE `commands` ADD COLUMN `resultJson` TEXT NULL'),
            ...$this->tablesWithIndexes(
                $this->activityEvents(),
                $this->eventNotifications(),
            ),
        );
    }

    private function activityEvents(): CreateTableStatement
    {
        return $this->prefixedIdTableCreatedOnly('activity_events')
            ->enum('level', ActivityLevel::class)
            ->string('type')
            ->text('message')
            ->string('entityType', nullable: true)
            ->string('entityId', length: 40, nullable: true)
            ->string('stashId', length: 40, nullable: true)
            ->string('mediaItemId', length: 40, nullable: true)
            ->string('broadcastId', length: 40, nullable: true)
            ->string('jobId', length: 40, nullable: true)
            ->string('commandId', length: 40, nullable: true)
            ->string('groupKey', nullable: true)
            ->text('metadataJson', nullable: true)
            ->index('createdAt')
            ->index('commandId')
            ->index('jobId');
    }

    private function eventNotifications(): CreateTableStatement
    {
        return $this->prefixedIdTableCreatedOnly('event_notifications')
            ->string('eventType')
            ->text('payloadJson')
            ->index('createdAt')
            ->index('eventType');
    }
}
