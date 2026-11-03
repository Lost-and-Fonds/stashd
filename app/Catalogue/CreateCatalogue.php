<?php

declare(strict_types=1);

namespace App\Catalogue;

use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\QueryStatements\RawStatement;
use Tempest\Database\ShouldMigrate;

/**
 * Add Catalogue membership while retaining every existing source URI and attempt.
 */
final class CreateCatalogue implements MigratesUp, ShouldMigrate
{
    public string $name = '2026-11-03_01_catalogue';

    /**
     * Add collection relationships and backfill canonical identities without replacing Integration records.
     */
    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new CreateTableStatement('catalogue_stashes')
                ->primary('uuid', uuid: true)
                ->datetime('created_at')
                ->datetime('updated_at')
                ->text('name'),
            new CreateTableStatement('catalogue_items')
                ->primary('uuid', uuid: true)
                ->datetime('created_at')
                ->datetime('updated_at')
                ->text('uri')
                ->raw('UNIQUE(uri)'),
            new CreateTableStatement('catalogue_stash_inputs')
                ->primary('uuid', uuid: true)
                ->datetime('created_at')
                ->datetime('updated_at')
                ->foreignUuid('stash_id', 'catalogue_stashes.uuid')
                ->foreignUuid('input_id', 'configurations.id')
                ->raw('UNIQUE(stash_id, input_id)'),
            new CreateTableStatement('catalogue_stash_items')
                ->primary('uuid', uuid: true)
                ->datetime('created_at')
                ->datetime('updated_at')
                ->foreignUuid('stash_id', 'catalogue_stashes.uuid')
                ->foreignUuid('item_id', 'catalogue_items.uuid')
                ->raw('UNIQUE(stash_id, item_id)'),
            new RawStatement(
                'INSERT INTO catalogue_items (uuid, created_at, updated_at, uri) SELECT gen_random_uuid(), CURRENT_TIMESTAMP(0), CURRENT_TIMESTAMP(0), item_uri FROM acquisition_sources',
            ),
            new RawStatement(
                'ALTER TABLE acquisition_sources ADD COLUMN uuid uuid NOT NULL DEFAULT gen_random_uuid(), ADD COLUMN created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP(0), ADD COLUMN updated_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP(0), ADD UNIQUE(uuid), ADD FOREIGN KEY(item_uri) REFERENCES catalogue_items(uri)',
            ),
        );
    }

    /**
     * Apply Catalogue tables on the existing PostgreSQL application connection.
     */
    public function shouldMigrate(Database $database): bool
    {
        return $database->dialect === DatabaseDialect::POSTGRESQL;
    }
}
