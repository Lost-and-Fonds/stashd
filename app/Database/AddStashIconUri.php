<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;

final class AddStashIconUri implements MigratesUp
{
    public string $name = '2026_06_20_add_stash_icon_uri';

    public function up(): QueryStatement
    {
        return new MigrationSqlStatement('ALTER TABLE `stashes` ADD COLUMN `iconUri` TEXT NULL');
    }
}
