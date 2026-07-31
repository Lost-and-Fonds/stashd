<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;

final class AddJobWorkloadIndexes implements MigratesUp
{
    public string $name = '2026_07_11_add_job_workload_indexes';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement('CREATE INDEX `jobs_pending_claim` ON `jobs` (`state`, `priority`, `createdAt`)'),
            new MigrationSqlStatement('CREATE INDEX `jobs_processing_heartbeat` ON `jobs` (`state`, `heartbeatAt`)'),
            new MigrationSqlStatement('CREATE INDEX `jobs_media_item_download_history` ON `jobs` (`entityType`, `intent`, `entityId`, `createdAt` DESC, `id` DESC)'),
        );
    }
}
