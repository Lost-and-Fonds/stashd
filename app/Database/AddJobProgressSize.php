<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;

final class AddJobProgressSize implements MigratesUp
{
    public string $name = '2026_09_22_add_job_progress_size';

    public function up(): QueryStatement
    {
        return new CompoundStatement(new MigrationSqlStatement('ALTER TABLE `jobs` ADD COLUMN IF NOT EXISTS `progressSizeBytes` BIGINT NULL'), new MigrationSqlStatement('ALTER TABLE `jobs` ADD COLUMN IF NOT EXISTS `progressSizeEstimated` BOOLEAN NOT NULL DEFAULT FALSE'));
    }
}
