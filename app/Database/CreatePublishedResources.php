<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\OnDelete;

final class CreatePublishedResources implements MigratesUp
{
    use MigrationSchemaHelpers;

    public string $name = '2026_08_21_create_published_resources';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement(
                $this->prefixedIdTable('published_resources')
                    ->raw($this->fkColumn('broadcastId', 40, 'broadcasts', OnDelete::CASCADE))
                    ->raw($this->fkColumn('assetId', 40, 'assets', OnDelete::CASCADE, nullable: true))
                    ->text('relativePath', nullable: true)
                    ->string('mediaType')
                    ->string('downloadName', nullable: true)
                    ->string('access')
                    ->raw($this->fkColumn('credentialSecretId', 40, 'secrets', OnDelete::SET_NULL, nullable: true))
                    ->string('state')
                    ->text('lastError', nullable: true)
                    ->index('broadcastId')
                    ->index('assetId')
                    ->index('credentialSecretId'),
            ),
            new MigrationSqlStatement('CREATE INDEX `published_resources_state` ON `published_resources` (`state`)'),
        );
    }
}
