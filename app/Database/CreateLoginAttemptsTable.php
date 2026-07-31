<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;

final class CreateLoginAttemptsTable implements MigratesUp
{
    public string $name = '2026_07_11_create_login_attempts';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement(
                'CREATE TABLE `login_attempts` (`keyHash` VARCHAR(64) NOT NULL PRIMARY KEY, `attempts` INTEGER NOT NULL, `expiresAt` DATETIME NOT NULL)',
                'CREATE TABLE "login_attempts" ("keyHash" VARCHAR(64) NOT NULL PRIMARY KEY, "attempts" INTEGER NOT NULL, "expiresAt" TIMESTAMP NOT NULL)',
            ),
            new MigrationSqlStatement('CREATE INDEX `login_attempts_expires_at` ON `login_attempts` (`expiresAt`)'),
        );
    }
}
