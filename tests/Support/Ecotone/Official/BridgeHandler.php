<?php

declare(strict_types=1);

namespace Evaluation\Official;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Tempest\Database\Database;
use Tempest\Database\Query;

/**
 * Verify the official worker and Tempest service injection without faking Operations.
 */
final class BridgeHandler
{
    /**
     * Record a diagnostic delivery through ordinary Tempest persistence.
     */
    #[Asynchronous('official_probe')]
    #[CommandHandler(endpointId: 'official.bridge')]
    public function handle(BridgePing $ping, Database $database): void
    {
        $database->execute(new Query('INSERT INTO official_effects (value) VALUES (?)', [$ping->value]));
    }
}
