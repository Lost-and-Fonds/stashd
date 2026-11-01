<?php

declare(strict_types=1);

namespace App\Operations\Persistence;

use App\Operations\Durable\DurableConnections;
use App\Operations\Models\AssignmentRecord;
use App\Operations\Models\Demand;
use App\Operations\Work;
use Doctrine\DBAL\Connection;
use Tempest\Container\Singleton;

use function Tempest\Database\query;

/**
 * Persist domain models and centralize PostgreSQL's canonical admission boundary.
 */
#[Singleton]
final readonly class Assignments
{
    public Connection $database;

    /**
     * Use the same application transaction as Tempest models and Messenger outbox.
     */
    public function __construct(DurableConnections $connections)
    {
        $this->database = $connections->application;
    }

    /**
     * Serialize admission and observation completion only for this exact Work.
     *
     * Call inside the short application transaction; never hold across a handler.
     */
    public function lock(Work $work): void
    {
        $this->database->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [json_encode([$work->type, $work->scope, $work->mode], JSON_THROW_ON_ERROR)]);
    }

    /**
     * Read the persisted admission model using Tempest's mapper and query builder.
     */
    public function find(string $id): ?AssignmentRecord
    {
        /** @var \Tempest\Database\Builder\QueryBuilders\SelectQueryBuilder<AssignmentRecord> $query */
        $query = query(AssignmentRecord::class)->select();
        $record = $query->where('id', $id)->first();
        assert($record === null || $record instanceof AssignmentRecord, 'Tempest must map an Assignment model.');

        return $record;
    }

    /**
     * Find the active request after obtaining its canonical Work lock.
     */
    public function active(Work $work): ?AssignmentRecord
    {
        /** @var \Tempest\Database\Builder\QueryBuilders\SelectQueryBuilder<AssignmentRecord> $query */
        $query = query(AssignmentRecord::class)->select();
        $record = $query
            ->where('work_type', $work->type)
            ->where('work_scope', $work->scope)
            ->where('work_mode', $work->mode)
            ->where('active', true)
            ->first();
        assert($record === null || $record instanceof AssignmentRecord, 'Tempest must map an Assignment model.');

        return $record;
    }

    /**
     * Retain a new domain record through the framework's ordinary model persistence.
     */
    public function insert(AssignmentRecord|Demand $model): void
    {
        query($model)->insert($model)->execute();
    }

    /**
     * Persist the admitted record without touching its immutable identity.
     */
    public function save(AssignmentRecord $model): void
    {
        query(AssignmentRecord::class)
            ->update(priority: $model->priority, active: $model->active, error: $model->error)
            ->where('id', (string) $model->id)
            ->execute();
    }

    /**
     * Pick a deterministic retained snapshot, not a caller's mutable latest state.
     */
    public function pending(string $assignment): ?Demand
    {
        /** @var \Tempest\Database\Builder\QueryBuilders\SelectQueryBuilder<Demand> $query */
        $query = query(Demand::class)->select();
        $record = $query->where('assignment_id', $assignment)->where('satisfied', false)->orderBy('id')->first();
        assert($record === null || $record instanceof Demand, 'Tempest must map a retained Demand model.');

        return $record;
    }

    /**
     * Load the immutable input selected by this workflow activity.
     */
    public function demand(string $id): ?Demand
    {
        /** @var \Tempest\Database\Builder\QueryBuilders\SelectQueryBuilder<Demand> $query */
        $query = query(Demand::class)->select();
        $record = $query->where('id', $id)->first();
        assert($record === null || $record instanceof Demand, 'Tempest must map a retained Demand model.');

        return $record;
    }

    /**
     * Coalesce the same observation without deleting newer or distinct demand.
     */
    public function observed(string $assignment, string $key): bool
    {
        return query(Demand::class)->select()->where('assignment_id', $assignment)->where('observation_key', $key)->first() !== null;
    }

    /**
     * Acknowledge only the observation actually executed.
     */
    public function satisfy(Demand $demand, ?string $next): void
    {
        query(Demand::class)
            ->update(satisfied: true, next_id: $next)
            ->where('id', (string) $demand->id)
            ->execute();
    }
}
