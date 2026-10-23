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
 * Adopt PR1's existing configuration table without replacing its rows or constraints.
 */
final class CreateConfigurationsTable implements MigratesUp, ShouldMigrate
{
    public string $name = '2026-10-23_01_create_configurations';

    /**
     * Create the operational baseline, including exact UUID and JSON object constraints.
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
     * Keep these private-store migrations out of the framework's unrelated default SQLite setup.
     */
    public function shouldMigrate(Database $database): bool
    {
        return $database->dialect === DatabaseDialect::POSTGRESQL;
    }
}
