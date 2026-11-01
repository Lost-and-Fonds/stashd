<?php

declare(strict_types=1);

namespace App\Operations\Persistence;

use App\Operations\Assignment;
use App\Operations\Durable\DurableConnections;
use App\Operations\Models\Demand;
use Doctrine\DBAL\Connection;
use Tempest\Container\Singleton;

use function Tempest\Database\query;

/**
 * Internal UUID persistence; admission and satisfaction callers hold the identity lock.
 */
#[Singleton]
final readonly class Assignments
{
    public Connection $database;

    public function __construct(DurableConnections $connections)
    {
        $this->database = $connections->application;
    }

    // Admission and satisfaction use the same lock inside short application transactions.
    public function lock(string $type, string $key): void
    {
        $this->database->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [json_encode([$type, $key], JSON_THROW_ON_ERROR)]);
    }

    public function find(string $uuid): ?Assignment
    {
        $record = query(Assignment::class)->select()->where('uuid', $uuid)->first();
        assert($record === null || $record instanceof Assignment, 'Tempest must hydrate the requested domain entity.');

        return $record;
    }

    public function active(string $type, string $key): ?Assignment
    {
        $record = query(Assignment::class)->select()->where('job_type', $type)->where('work_key', $key)->where('active', true)->first();
        assert($record === null || $record instanceof Assignment, 'Tempest must hydrate the requested domain entity.');

        return $record;
    }

    public function insert(Assignment|Demand $model): void
    {
        query($model)->insert($model)->execute();
    }

    public function update(Assignment $model): void
    {
        query(Assignment::class)
            ->update(priority: $model->priority, active: $model->active, error: $model->error)
            ->where('uuid', (string) $model->uuid)
            ->execute();
    }

    public function pending(string $assignment): ?Demand
    {
        $record = query(Demand::class)->select()->where('assignment_id', $assignment)->where('satisfied', false)->orderBy('uuid')->first();
        assert($record === null || $record instanceof Demand, 'Tempest must hydrate the requested domain entity.');

        return $record;
    }

    public function demand(string $uuid): ?Demand
    {
        $record = query(Demand::class)->select()->where('uuid', $uuid)->first();
        assert($record === null || $record instanceof Demand, 'Tempest must hydrate the requested domain entity.');

        return $record;
    }

    public function observed(string $assignment, string $key): ?Demand
    {
        $record = query(Demand::class)->select()->where('assignment_id', $assignment)->where('observation_key', $key)->first();
        assert($record === null || $record instanceof Demand, 'Tempest must hydrate the requested domain entity.');

        return $record;
    }

    public function satisfy(Demand $demand, ?string $next): void
    {
        query(Demand::class)
            ->update(satisfied: true, next_id: $next)
            ->where('uuid', (string) $demand->uuid)
            ->execute();
    }
}
