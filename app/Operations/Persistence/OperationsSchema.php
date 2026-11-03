<?php

declare(strict_types=1);

namespace App\Operations\Persistence;

use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\QueryStatements\RawStatement;
use Tempest\Database\ShouldMigrate;

/**
 * Define departmental admission tables while leaving upstream schemas upstream.
 */
final class OperationsSchema implements MigratesUp, ShouldMigrate
{
    public string $name = '2026-11-01_01_operations_assignments';

    /**
     * Keep active Work unique even if admission locking is accidentally bypassed.
     */
    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new CreateTableStatement('operation_assignments')
                ->primary('uuid', uuid: true)
                ->datetime('created_at')
                ->datetime('updated_at')
                ->text('job_type')
                ->text('work_key')
                ->text('priority')
                ->boolean('active', default: true)
                ->text('error', nullable: true)
                ->raw("CHECK (priority IN ('routine', 'interactive'))"),
            new RawStatement('CREATE UNIQUE INDEX operation_active_work ON operation_assignments(job_type, work_key) WHERE active'),
            new CreateTableStatement('operation_demands')
                ->primary('uuid', uuid: true)
                ->datetime('created_at')
                ->datetime('updated_at')
                ->foreignUuid('assignment_id', 'operation_assignments.uuid')
                ->text('observation')
                ->text('observation_key')
                ->text('payload')
                ->boolean('satisfied', default: false)
                ->foreignUuid('next_id', 'operation_demands.uuid', nullable: true)
                ->raw('current_attempt UUID NULL')
                ->raw('UNIQUE(assignment_id, observation_key)'),
            new CreateTableStatement('operation_attempts')
                ->primary('uuid', uuid: true)
                ->datetime('created_at')
                ->datetime('updated_at')
                ->foreignUuid('assignment_id', 'operation_assignments.uuid')
                ->foreignUuid('demand_id', 'operation_demands.uuid')
                ->integer('backend_pid')
                ->text('backend_start')
                ->raw('lock_key BIGINT NOT NULL')
                ->text('outcome')
                ->datetime('started_at')
                ->datetime('ended_at', nullable: true)
                ->raw("CHECK (outcome IN ('running', 'completed', 'failed', 'interrupted'))"),
            new RawStatement('ALTER TABLE operation_demands ADD FOREIGN KEY (current_attempt) REFERENCES operation_attempts(uuid)'),
            new CreateTableStatement('operation_checkpoints')
                ->primary(uuid: true)
                ->foreignUuid('assignment_id', 'operation_assignments.uuid')
                ->foreignUuid('demand_id', 'operation_demands.uuid')
                ->foreignUuid('attempt_id', 'operation_attempts.uuid')
                ->text('value'),
            new CreateTableStatement('operation_custody_receipts')
                ->primary(uuid: true)
                ->foreignUuid('assignment_id', 'operation_assignments.uuid')
                ->foreignUuid('demand_id', 'operation_demands.uuid')
                ->foreignUuid('attempt_id', 'operation_attempts.uuid')
                ->text('key')
                ->text('path')
                ->text('digest')
                ->raw('UNIQUE(assignment_id, key)'),
            new CreateTableStatement('operation_results')
                ->primary('uuid', uuid: true)
                ->datetime('created_at')
                ->datetime('updated_at')
                ->foreignUuid('assignment_id', 'operation_assignments.uuid')
                ->foreignUuid('demand_id', 'operation_demands.uuid')
                ->foreignUuid('attempt_id', 'operation_attempts.uuid')
                ->text('coverage')
                ->raw('UNIQUE(demand_id)'),
            new CreateTableStatement('operation_reconciliations')
                ->primary(uuid: true)
                ->foreignUuid('attempt_id', 'operation_attempts.uuid')
                ->foreignUuid('receipt_id', 'operation_custody_receipts')
                ->raw('UNIQUE(attempt_id, receipt_id)'),
        );
    }

    public function shouldMigrate(Database $database): bool
    {
        return $database->dialect === DatabaseDialect::POSTGRESQL;
    }
}
