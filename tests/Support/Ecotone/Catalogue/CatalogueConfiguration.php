<?php

declare(strict_types=1);

namespace Tests\Support\Ecotone\Catalogue;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;

/**
 * Add three independent catalogue deliveries to the real application messaging system.
 */
final readonly class CatalogueConfiguration
{
    /**
     * Store each subscriber invitation on the application's one native connection.
     */
    #[ServiceContext]
    public function channels(): array
    {
        return array_map(static fn (string $name) => DbalBackedMessageChannelBuilder::create($name)
            ->withReceiveTimeout(1)
            ->withAutoDeclare(false), ['history', 'premis', 'client']);
    }
}
