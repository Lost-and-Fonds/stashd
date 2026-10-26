<?php

namespace App\Integration\Migrations;

use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\ShouldMigrate;

/**
 * Add operational source identities and durable acquisition attempts without changing archives.
 */
final class CreateAcquisitionTablesTable implements MigratesUp, ShouldMigrate
{
    public string $name = '2026-10-26_03_create_acquisition_tables';

    /**
     * Create source and attempt uniqueness constraints so retries retain one identity.
     *
     * The migration ledger applies these additions once without replacing existing rows.
     */
    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new CreateTableStatement('acquisition_sources')
                ->raw('input_id uuid NOT NULL REFERENCES configurations(id)')
                ->text('source_id')
                ->text('item_uri')
                ->raw('PRIMARY KEY (input_id, source_id)')
                ->raw('UNIQUE (item_uri)'),
            new CreateTableStatement('acquisition_attempts')
                ->primary(uuid: true)
                ->raw('run_id uuid NOT NULL REFERENCES discovery_runs(id)')
                ->text('source_id')
                ->text('item_uri')
                ->text('status')
                ->json('result', nullable: true)
                ->text('error', nullable: true)
                ->raw('UNIQUE (run_id, source_id)'),
        );
    }

    /**
     * Restrict these operational tables to the existing PostgreSQL connection.
     *
     * Select by connection tag if another PostgreSQL connection is introduced.
     */
    public function shouldMigrate(Database $database): bool
    {
        return $database->dialect === DatabaseDialect::POSTGRESQL;
    }
}
