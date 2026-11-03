<?php

declare(strict_types=1);

namespace App\Integration\Migrations;

use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\RawStatement;
use Tempest\Database\ShouldMigrate;

/**
 * Extend existing runs and attempts with admission progress, retaining every preservation identity.
 */
final class AddDirectoryAdmission implements MigratesUp, ShouldMigrate
{
    public string $name = '2026-11-03_02_directory_admission';

    /**
     * Add nullable progress for existing runs; each per-source attempt points to its Operations Assignment.
     */
    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new RawStatement('ALTER TABLE discovery_runs ADD COLUMN admission_cursor text NULL'),
            new RawStatement('ALTER TABLE acquisition_attempts ADD COLUMN assignment_id uuid NULL UNIQUE REFERENCES operation_assignments(uuid)'),
        );
    }

    /**
     * Use the existing PostgreSQL application connection and migration ledger.
     */
    public function shouldMigrate(Database $database): bool
    {
        return $database->dialect === DatabaseDialect::POSTGRESQL;
    }
}
