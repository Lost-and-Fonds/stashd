<?php

declare(strict_types=1);

namespace App\Operations\Persistence;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\QueryStatements\RawStatement;

/**
 * Define departmental admission tables while leaving upstream schemas upstream.
 */
final class OperationsSchema implements MigratesUp, \Tempest\Database\ShouldMigrate
{
    public string $name = '2026-11-01_01_operations_assignments';

    /**
     * Keep active Work unique even if admission locking is accidentally bypassed.
     */
    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new CreateTableStatement('operation_assignments')
                ->primary(uuid: true)
                ->text('work_type')
                ->text('work_scope')
                ->text('work_mode')
                ->text('priority')
                ->boolean('active', default: true)
                ->text('error', nullable: true)
                ->raw("CHECK (priority IN ('routine', 'interactive'))"),
            new RawStatement('CREATE UNIQUE INDEX operation_active_work ON operation_assignments(work_type, work_scope, work_mode) WHERE active'),
            new CreateTableStatement('operation_demands')
                ->primary(uuid: true)
                ->foreignUuid('assignment_id', 'operation_assignments')
                ->text('observation')
                ->text('observation_key')
                ->text('job_class')
                ->text('payload')
                ->boolean('satisfied', default: false)
                ->foreignUuid('next_id', 'operation_demands', nullable: true)
                ->raw('current_attempt UUID NULL')
                ->raw('UNIQUE(assignment_id, observation_key)'),
            new CreateTableStatement('operation_attempts')
                ->primary(uuid: true)
                ->foreignUuid('assignment_id', 'operation_assignments')
                ->foreignUuid('demand_id', 'operation_demands')
                ->integer('backend_pid')
                ->text('backend_start')
                ->text('outcome')
                ->datetime('started_at')
                ->datetime('ended_at', nullable: true)
                ->raw("CHECK (outcome IN ('running', 'completed', 'failed', 'interrupted'))"),
            new RawStatement('ALTER TABLE operation_demands ADD FOREIGN KEY (current_attempt) REFERENCES operation_attempts(id)'),
            new CreateTableStatement('operation_artifacts')
                ->primary(uuid: true)
                ->foreignUuid('assignment_id', 'operation_assignments')
                ->foreignUuid('demand_id', 'operation_demands')
                ->foreignUuid('attempt_id', 'operation_attempts')
                ->text('kind')
                ->text('value')
                ->text('path', nullable: true)
                ->text('digest', nullable: true)
                ->raw("CHECK (kind IN ('checkpoint', 'custody', 'result'))"),
            new CreateTableStatement('operation_reconciliations')
                ->primary(uuid: true)
                ->foreignUuid('attempt_id', 'operation_attempts')
                ->foreignUuid('artifact_id', 'operation_artifacts')
                ->raw('UNIQUE(attempt_id, artifact_id)'),
        );
    }

    /**
     * Require PostgreSQL for partial uniqueness and the domain authority boundary.
     */
    public function shouldMigrate(\Tempest\Database\Database $database): bool
    {
        return $database->dialect === \Tempest\Database\Config\DatabaseDialect::POSTGRESQL;
    }
}
