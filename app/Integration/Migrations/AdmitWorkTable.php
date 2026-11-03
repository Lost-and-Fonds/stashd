<?php

declare(strict_types=1);

namespace App\Integration\Migrations;

use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\AlterTableStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\QueryStatements\RawStatement;
use Tempest\Database\QueryStatements\TextStatement;
use Tempest\Database\ShouldMigrate;

/**
 * Extend the existing Job store without discarding legacy keys or history.
 */
final class AdmitWorkTable implements MigratesUp, ShouldMigrate
{
    public string $name = '2026-10-30_01_admit_work';

    /**
     * Reserve canonical Work only while active and retain physical Attempts.
     *
     * Legacy example admissions retain their permanent idempotency keys.
     */
    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new AlterTableStatement('jobs')
                ->add(new TextStatement('work_type', nullable: true))
                ->add(new TextStatement('work_scope', nullable: true))
                ->add(new TextStatement('work_mode', nullable: true))
                ->add(new TextStatement('desired_priority', default: 'routine')),
            new RawStatement("ALTER TABLE jobs ADD CONSTRAINT jobs_priority CHECK (desired_priority IN ('routine', 'interactive'))"),
            new RawStatement("CREATE UNIQUE INDEX jobs_active_work ON jobs (work_type, work_scope, work_mode) WHERE status IN ('queued', 'running')"),
            new CreateTableStatement('job_attempts')
                ->primary(uuid: true)
                ->raw('job_id UUID NOT NULL REFERENCES jobs(id)')
                ->raw('started_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()')
                ->raw('ended_at TIMESTAMPTZ NULL')
                ->text('outcome', nullable: true),
        );
    }

    /**
     * Require PostgreSQL for the partial unique index and transaction locks.
     */
    public function shouldMigrate(Database $database): bool
    {
        return $database->dialect === DatabaseDialect::POSTGRESQL;
    }
}
