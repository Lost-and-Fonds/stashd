<?php

declare(strict_types=1);

namespace App\Operations\Durable;

use Doctrine\DBAL\Connection;

/**
 * Supply explicit isolated connections to the experimental Tempest initializer.
 *
 * The host owns credentials and connection lifetime; journal fencing must commit
 * independently of business transactions. Never share these handles with a fork.
 */
final readonly class DurableConnections
{
    /**
     * Separate business writes, the journal and session ownership deliberately.
     */
    public function __construct(
        public Connection $application,
        public Connection $journal,
        public Connection $claims,
    ) {}
}
