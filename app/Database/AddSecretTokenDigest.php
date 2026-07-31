<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;

final class AddSecretTokenDigest implements MigratesUp
{
    public string $name = '2026_07_11_add_secret_token_digest';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement('ALTER TABLE `secrets` ADD COLUMN `tokenDigest` VARCHAR(64) NULL'),
            new MigrationSqlStatement('CREATE UNIQUE INDEX `secrets_token_digest` ON `secrets` (`tokenDigest`)'),
        );
    }
}
