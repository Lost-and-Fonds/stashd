<?php

namespace App\Integration\Migrations;

use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\QueryStatements\RawStatement;
use Tempest\Database\ShouldMigrate;

/**
 * Add tables for scan runs, found files and source locations without replacing saved data.
 */
final class CreateDiscoveryTablesTable implements MigratesUp, ShouldMigrate
{
    public string $name = '2026-10-23_02_create_discovery_tables';

    /**
     * Create scan tables with references to their Input and run.
     *
     * Source IDs are unique within each Input or run, and only one unfinished run
     * may exist per Input. Keep existing tables and add the index if it is missing.
     */
    public function up(): QueryStatement
    {
        $tables = [
            new CreateTableStatement('discovery_runs')
                ->primary(uuid: true)
                ->raw('input_id uuid NOT NULL REFERENCES configurations(id)')
                ->json('configuration')
                ->text('status')
                ->raw("CHECK (status IN ('running', 'finished'))")
                ->text('continuation', nullable: true)
                ->json('coverage', nullable: true)
                ->integer('batches', default: 0)
                ->text('error', nullable: true),
            new CreateTableStatement('source_associations')
                ->raw('input_id uuid NOT NULL REFERENCES configurations(id)')
                ->text('source_id')
                ->raw('first_run uuid NOT NULL REFERENCES discovery_runs(id)')
                ->raw('last_run uuid NOT NULL REFERENCES discovery_runs(id)')
                ->raw('PRIMARY KEY (input_id, source_id)'),
            new CreateTableStatement('discovery_items')
                ->raw('run_id uuid NOT NULL REFERENCES discovery_runs(id)')
                ->text('source_id')
                ->json('item')
                ->raw('PRIMARY KEY (run_id, source_id)'),
        ];
        $statements = array_map(
            static fn (CreateTableStatement $table): RawStatement => new RawStatement(str_replace(
                'CREATE TABLE ',
                'CREATE TABLE IF NOT EXISTS ',
                $table->compile(DatabaseDialect::POSTGRESQL),
            )),
            $tables,
        );
        $statements[] = new RawStatement("CREATE UNIQUE INDEX IF NOT EXISTS discovery_active_input ON discovery_runs (input_id) WHERE status = 'running'");

        return new CompoundStatement(...$statements);
    }

    /**
     * Run this migration only on PostgreSQL.
     *
     * If Core adds another PostgreSQL connection, select the operational connection by tag.
     */
    public function shouldMigrate(Database $database): bool
    {
        return $database->dialect === DatabaseDialect::POSTGRESQL;
    }
}
