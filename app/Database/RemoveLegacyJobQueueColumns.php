<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;

final class RemoveLegacyJobQueueColumns implements MigratesUp
{
    public string $name = '2026_09_04_remove_legacy_job_queue_columns';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement('DROP INDEX IF EXISTS `jobs_pending_claim`'),
            new MigrationSqlStatement('DROP INDEX IF EXISTS `jobs_processing_heartbeat`'),
            new MigrationSqlStatement(<<<'SQL'
                ALTER TABLE jobs
                    DROP COLUMN IF EXISTS `priority`,
                    DROP COLUMN IF EXISTS `maxAttempts`,
                    DROP COLUMN IF EXISTS `scheduledAt`,
                    DROP COLUMN IF EXISTS `heartbeatAt`,
                    DROP COLUMN IF EXISTS `ownerToken`
                SQL),
        );
    }
}
