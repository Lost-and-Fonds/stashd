<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\OnDelete;

final class AddPodcastBroadcastItemTokens implements MigratesUp
{
    use MigrationSchemaHelpers;

    public string $name = '2026_06_19_add_podcast_broadcast_item_tokens';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement(
                sprintf(
                    'ALTER TABLE `broadcast_items` ADD COLUMN %s',
                    $this->fkColumn('tokenSecretId', 40, 'secrets', OnDelete::SET_NULL, nullable: true),
                ),
            ),
            new MigrationSqlStatement('ALTER TABLE `broadcast_items` ADD COLUMN `tokenPreview` VARCHAR(255) NULL'),
        );
    }
}
