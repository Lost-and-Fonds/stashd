<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;

final class RemoveMediaItemSizeEstimate implements MigratesUp
{
    public string $name = '2026_09_22_remove_media_item_size_estimate';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement('ALTER TABLE IF EXISTS `media_items` DROP COLUMN IF EXISTS `sizeBytes`'),
            new MigrationSqlStatement('ALTER TABLE IF EXISTS `media_items` DROP COLUMN IF EXISTS `sizeEstimated`'),
            new MigrationSqlStatement('ALTER TABLE IF EXISTS `items` DROP COLUMN IF EXISTS `sizeBytes`'),
            new MigrationSqlStatement('ALTER TABLE IF EXISTS `items` DROP COLUMN IF EXISTS `sizeEstimated`'),
        );
    }
}
