<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;

/**
 * Stashd is single-owner in v1 -- collecting an email address never bought
 * anything (no password reset flow, no notifications sent to it). Replaces
 * it with username as the login identifier, backfilling from the local part
 * of the existing email for any admin created before this migration.
 */
final class ReplaceUserEmailWithUsername implements MigratesUp
{
    public string $name = '2026_07_04_replace_user_email_with_username';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement('ALTER TABLE `users` ADD COLUMN `username` VARCHAR(255) NULL'),
            new MigrationSqlStatement("UPDATE \"users\" SET \"username\" = split_part(\"email\", '@', 1) WHERE \"username\" IS NULL"),
            new MigrationSqlStatement('CREATE UNIQUE INDEX `users_username` ON `users` (`username`)'),
            new MigrationSqlStatement('DROP INDEX IF EXISTS `users_email`'),
            new MigrationSqlStatement('ALTER TABLE `users` DROP COLUMN `email`'),
        );
    }
}
