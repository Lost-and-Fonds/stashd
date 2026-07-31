<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;

final class AddStashInputOptions implements MigratesUp
{
    public string $name = '2026_06_22_add_stash_input_options';

    public function up(): QueryStatement
    {
        return new MigrationSqlStatement('ALTER TABLE `stash_inputs` ADD COLUMN `optionsJson` TEXT NULL');
    }
}
