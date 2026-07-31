<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;

final class AddMediaItemContentType implements MigratesUp
{
    public string $name = '2026_06_21_add_media_item_content_type';

    public function up(): QueryStatement
    {
        return new MigrationSqlStatement('ALTER TABLE `media_items` ADD COLUMN `contentType` TEXT NULL');
    }
}
