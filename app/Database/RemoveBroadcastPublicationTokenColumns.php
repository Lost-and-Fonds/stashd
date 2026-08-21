<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;

/** Remove the obsolete per-Broadcast/item token columns now that publications own access credentials. */
final class RemoveBroadcastPublicationTokenColumns implements MigratesUp
{
    public string $name = '2026_08_21_remove_broadcast_publication_token_columns';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement('SELECT 1', 'ALTER TABLE "broadcasts" DROP COLUMN IF EXISTS "tokenSecretId"'),
            new MigrationSqlStatement('SELECT 1', 'ALTER TABLE "broadcasts" DROP COLUMN IF EXISTS "tokenPreview"'),
            new MigrationSqlStatement('SELECT 1', 'ALTER TABLE "broadcast_items" DROP COLUMN IF EXISTS "tokenSecretId"'),
            new MigrationSqlStatement('SELECT 1', 'ALTER TABLE "broadcast_items" DROP COLUMN IF EXISTS "tokenPreview"'),
        );
    }
}
