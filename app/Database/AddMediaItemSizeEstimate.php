<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;

final class AddMediaItemSizeEstimate implements MigratesUp
{
    public string $name = '2026_08_31_add_media_item_size_estimate';

    public function up(): QueryStatement
    {
        return new MigrationSqlStatement('ALTER TABLE `media_items` ADD COLUMN `sizeBytes` BIGINT NULL, ADD COLUMN `sizeEstimated` BOOLEAN NOT NULL DEFAULT FALSE');
    }
}
