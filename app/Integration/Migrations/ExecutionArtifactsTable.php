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
 * Retain bounded execution evidence independently of worker and transport lifetime.
 */
final class ExecutionArtifactsTable implements MigratesUp, ShouldMigrate
{
    public string $name = '2026-10-31_01_execution_artifacts';

    /**
     * Keep receipts append-only and reconciliation tied to the actual Attempt.
     */
    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new CreateTableStatement('execution_artifacts')
                ->raw('id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY')
                ->raw('job_id UUID NOT NULL REFERENCES jobs(id)')
                ->raw('attempt_id UUID NOT NULL REFERENCES job_attempts(id)')
                ->text('kind')
                ->text('value')
                ->text('path', nullable: true)
                ->text('digest', nullable: true)
                ->raw('acknowledged_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()'),
            new CreateTableStatement('execution_reconciliations')
                ->raw('attempt_id UUID NOT NULL REFERENCES job_attempts(id)')
                ->raw('artifact_id BIGINT NOT NULL REFERENCES execution_artifacts(id)')
                ->raw('PRIMARY KEY (attempt_id, artifact_id)'),
        );
    }

    /**
     * Match the PostgreSQL execution store used by Operations.
     */
    public function shouldMigrate(Database $database): bool
    {
        return $database->dialect === DatabaseDialect::POSTGRESQL;
    }
}
