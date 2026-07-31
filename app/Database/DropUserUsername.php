<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;

final class DropUserUsername implements MigratesUp
{
    public string $name = '2026_07_01_drop_user_username';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement('DROP INDEX IF EXISTS `users_username`'),
            new MigrationSqlStatement('ALTER TABLE `users` DROP COLUMN `username`'),
        );
    }
}
