<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;

final class DropStashSlug implements MigratesUp
{
    public string $name = '2026_07_15_drop_stash_slug';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement('DROP INDEX IF EXISTS `stashes_slug`'),
            new MigrationSqlStatement('ALTER TABLE `stashes` DROP COLUMN `slug`'),
        );
    }
}
