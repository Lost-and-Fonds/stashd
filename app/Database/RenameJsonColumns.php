<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;

final class RenameJsonColumns implements MigratesUp
{
    public string $name = '2026_07_03_rename_json_columns';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement('ALTER TABLE `commands` RENAME COLUMN `optionsJson` TO `options`'),
            new MigrationSqlStatement('ALTER TABLE `commands` RENAME COLUMN `resultJson` TO `result`'),
            new MigrationSqlStatement('ALTER TABLE `jobs` RENAME COLUMN `payloadJson` TO `payload`'),
            new MigrationSqlStatement('ALTER TABLE `broadcasts` RENAME COLUMN `settingsJson` TO `settings`'),
            new MigrationSqlStatement('ALTER TABLE `broadcast_triggers` RENAME COLUMN `settingsJson` TO `settings`'),
            new MigrationSqlStatement('ALTER TABLE `media_server_connections` RENAME COLUMN `settingsJson` TO `settings`'),
            new MigrationSqlStatement('ALTER TABLE `activity_events` RENAME COLUMN `metadataJson` TO `metadata`'),
            new MigrationSqlStatement('ALTER TABLE `secrets` RENAME COLUMN `metadataJson` TO `metadata`'),
            new MigrationSqlStatement('ALTER TABLE `storage_checks` RENAME COLUMN `detailsJson` TO `details`'),
            new MigrationSqlStatement('ALTER TABLE `event_notifications` RENAME COLUMN `payloadJson` TO `payload`'),
            new MigrationSqlStatement('ALTER TABLE `stash_inputs` RENAME COLUMN `optionsJson` TO `options`'),
            new MigrationSqlStatement('ALTER TABLE `api_tokens` RENAME COLUMN `scopesJson` TO `scopes`'),
        );
    }
}
