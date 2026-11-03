<?php

declare(strict_types=1);

namespace App\Operations\Persistence;

use App\Operations\Durable\DurableConnections;
use Doctrine\DBAL\Connection;
use Tempest\Container\Singleton;

/**
 * Serialize admission and final satisfaction for the same stable Job type and key.
 */
#[Singleton]
final readonly class AdmissionLock
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
}
