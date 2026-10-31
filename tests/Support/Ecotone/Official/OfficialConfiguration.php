<?php

declare(strict_types=1);

namespace Evaluation\Official;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Tempest\TempestConnectionReference;

/**
 * Configure the official integration using its documented native connection reference.
 * Only the diagnostic namespace is scanned; Core's candidate bus is not substituted.
 */
final class OfficialConfiguration
{
    /**
     * Use native Tempest connection resolution and explicit short-transaction policy.
     */
    #[ServiceContext]
    public function configuration(): array
    {
        return [
            TempestConnectionReference::defaultConnection(),
            DbalBackedMessageChannelBuilder::create('jobs')->withReceiveTimeout(1),
            DbalBackedMessageChannelBuilder::create('official_probe')->withReceiveTimeout(1),
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false)->withTransactionOnAsynchronousEndpoints(false)->withDeduplication(false),
        ];
    }
}
