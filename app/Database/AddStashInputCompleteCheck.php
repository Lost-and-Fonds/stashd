<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;

final class AddStashInputCompleteCheck implements MigratesUp
{
    public string $name = '2026_09_15_add_stash_input_complete_check';

    public function up(): QueryStatement
    {
        return new MigrationSqlStatement('ALTER TABLE `stash_inputs` ADD COLUMN `nextCompleteCheckAt` TIMESTAMP NULL');
    }
}
