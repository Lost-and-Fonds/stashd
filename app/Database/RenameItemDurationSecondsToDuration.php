<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;

final class RenameItemDurationSecondsToDuration implements MigratesUp
{
    public string $name = '2026_09_09_rename_item_duration_seconds_to_duration';

    public function up(): QueryStatement
    {
        return new MigrationSqlStatement('ALTER TABLE `items` RENAME COLUMN `durationSeconds` TO `duration`');
    }
}
