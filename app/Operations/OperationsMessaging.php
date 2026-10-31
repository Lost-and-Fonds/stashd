<?php

declare(strict_types=1);

namespace App\Operations;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Tempest\TempestConnectionReference;

/**
 * Configure native durable delivery and finite infrastructure retries on the shared owner.
 * Domain Attempts keep their separate budget; claims do not wrap arbitrary external effects.
 */
final readonly class OperationsMessaging
{
    /**
     * Declare one local queue with explicit provisioning and native dead-letter recovery.
     */
    #[ServiceContext]
    public function configuration(): array
    {
        return [
            TempestConnectionReference::defaultConnection()->withConnectionConfiguration(['redelivery_delay' => 60_000]),
            DbalBackedMessageChannelBuilder::create('jobs')->withReceiveTimeout(1)->withAutoDeclare(false),
            ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('operations.errors'),
            ErrorHandlerConfiguration::createWithDeadLetterChannel('operations.errors', RetryTemplateBuilder::fixedBackOff(1000)->maxRetries(3), 'dbal_dead_letter'),
            InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
            DbalConfiguration::createWithDefaults()
                ->withTransactionOnAsynchronousEndpoints(false)
                ->withTransactionOnCommandBus(false)
                ->withDeduplication(false)
                ->withDeadLetter(true),
        ];
    }
}
