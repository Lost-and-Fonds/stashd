<?php

declare(strict_types=1);

namespace App\Operations;

use Ecotone\Dbal\Database\DeadLetterTableManager;
use Ecotone\Dbal\Database\EnqueueTableManager;
use Ecotone\Dbal\Recoverability\DbalDeadLetterStore;
use Tempest\Console\ConsoleCommand;

/**
 * Provision vendor-owned delivery schemas explicitly before consumers start.
 * Application domain schema remains managed by Tempest migrations.
 */
final readonly class DeliverySchema
{
    /**
     * Resolve the same connection owner as ordinary application persistence.
     */
    public function __construct(
        private SharedOperationsConnection $connection,
    ) {}

    /**
     * Create missing native queue and dead-letter tables without deleting intent.
     * Run after integration:init, once per deployment, before starting consumers.
     */
    #[ConsoleCommand(name: 'job:setup', description: 'Provision native Ecotone delivery tables without deleting messages.')]
    public function provision(): void
    {
        $database = $this->connection->database();
        new EnqueueTableManager('enqueue', true)->createTable($database);
        new DeadLetterTableManager(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE, true)->createTable($database);
    }
}
