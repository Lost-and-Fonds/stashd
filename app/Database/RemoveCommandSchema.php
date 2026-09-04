<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;

final class RemoveCommandSchema implements MigratesUp
{
    public string $name = '2026_09_04_remove_command_schema';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement('ALTER TABLE `jobs` DROP CONSTRAINT IF EXISTS `jobs_commandId_fkey`'),
            new MigrationSqlStatement('ALTER TABLE `jobs` DROP COLUMN IF EXISTS `commandId`'),
            new MigrationSqlStatement('DROP TABLE IF EXISTS `commands`'),
        );
    }
}
