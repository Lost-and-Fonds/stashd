<?php

namespace App\Integration\Migrations;

use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\ShouldMigrate;

/**
 * Create durable Job state without owning the native delivery schema.
 * Ecotone provisions its queue and dead-letter tables explicitly after migrations.
 */
final class CreateJobsTable implements MigratesUp, ShouldMigrate
{
    public string $name = '2026-10-28_01_create_jobs';

    /**
     * Keep Job identity, counters and claim-state constraints in ordinary application migrations.
     */
    public function up(): QueryStatement
    {
        return new CreateTableStatement('jobs')
            ->primary(uuid: true)
            ->text('idempotency_key')
            ->text('payload')
            ->text('status')
            ->integer('attempts', default: 0)
            ->integer('failures', default: 0)
            ->integer('generation', default: 0)
            ->text('claim_token', nullable: true)
            ->raw('lease_until timestamptz NULL')
            ->text('error', nullable: true)
            ->raw('UNIQUE (idempotency_key)')
            ->raw("CHECK (status IN ('queued', 'running', 'completed', 'failed'))")
            ->raw("CHECK ((status = 'running' AND claim_token IS NOT NULL AND lease_until IS NOT NULL) OR (status <> 'running' AND claim_token IS NULL AND lease_until IS NULL))")
            ->raw('CHECK (attempts >= 0 AND failures >= 0 AND failures <= attempts AND generation >= 0)');
    }

    /**
     * Require the PostgreSQL dialect used by Operations' atomic claim statements.
     */
    public function shouldMigrate(Database $database): bool
    {
        return $database->dialect === DatabaseDialect::POSTGRESQL;
    }
}
