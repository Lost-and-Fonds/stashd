<?php

namespace App\Integration\Migrations;

use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\QueryStatements\RawStatement;
use Tempest\Database\ShouldMigrate;

/**
 * Create the configuration table, or keep the existing table from PR1 unchanged.
 */
final class CreateConfigurationsTable implements MigratesUp, ShouldMigrate
{
    public string $name = '2026-10-23_01_create_configurations';

    /**
     * Create configuration records with UUID keys and database checks on kind and settings.
     */
    public function up(): QueryStatement
    {
        $table = new CreateTableStatement('configurations')
            ->primary(uuid: true)
            ->text('kind')
            ->text('component')
            ->json('settings')
            ->raw("CHECK (kind IN ('input', 'broadcast'))")
            ->raw("CHECK (jsonb_typeof(settings) = 'object')");

        return new RawStatement(str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $table->compile(DatabaseDialect::POSTGRESQL)));
    }

    /**
     * Skip this PostgreSQL migration when Tempest uses its default SQLite connection.
     *
     * If Core adds another PostgreSQL connection, select the operational connection by tag.
     */
    public function shouldMigrate(Database $database): bool
    {
        return $database->dialect === DatabaseDialect::POSTGRESQL;
    }
}
