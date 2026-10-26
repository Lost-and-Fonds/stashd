<?php

declare(strict_types=1);

namespace App\Integration\Migrations;

use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\ShouldMigrate;

/**
 * Keep frozen publication selections and provisional results outside canonical archives.
 */
final class CreateBroadcastTablesTable implements MigratesUp, ShouldMigrate
{
    public string $name = '2026-10-26_04_create_broadcast_tables';

    /**
     * Retain bounded ordered records and isolate each publication attempt's reports.
     */
    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new CreateTableStatement('broadcast_runs')
                ->primary(uuid: true)
                ->raw('broadcast_id uuid NOT NULL REFERENCES configurations(id)')
                ->json('settings')
                ->text('status')
                ->text('attempt', nullable: true)
                ->text('files', nullable: true)
                ->text('error', nullable: true),
            new CreateTableStatement('broadcast_selection')
                ->raw('run_id uuid NOT NULL REFERENCES broadcast_runs(id)')
                ->integer('position')
                ->text('item_id')
                ->text('version')
                ->json('record')
                ->raw('PRIMARY KEY (run_id, position)')
                ->raw('UNIQUE (run_id, item_id)'),
            new CreateTableStatement('broadcast_reports')
                ->raw('run_id uuid NOT NULL REFERENCES broadcast_runs(id)')
                ->text('attempt')
                ->integer('position')
                ->text('kind')
                ->text('path', nullable: true)
                ->json('record')
                ->raw('PRIMARY KEY (run_id, attempt, position)')
                ->raw('UNIQUE (run_id, attempt, path)'),
        );
    }

    /**
     * Use the same PostgreSQL operational connection as the earlier integration tables.
     */
    public function shouldMigrate(Database $database): bool
    {
        return $database->dialect === DatabaseDialect::POSTGRESQL;
    }
}
