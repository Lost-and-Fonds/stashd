<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;

final class AddJobOwnerToken implements MigratesUp
{
    public string $name = '2026_07_05_add_job_owner_token';

    public function up(): QueryStatement
    {
        return new MigrationSqlStatement('ALTER TABLE `jobs` ADD COLUMN `ownerToken` TEXT NULL');
    }
}
