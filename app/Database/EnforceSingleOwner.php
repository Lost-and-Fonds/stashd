<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;

final class EnforceSingleOwner implements MigratesUp
{
    public string $name = '2026_07_11_enforce_single_owner';

    public function up(): QueryStatement
    {
        return new MigrationSqlStatement('CREATE UNIQUE INDEX `users_single_owner` ON `users` ((1))');
    }
}
