<?php

declare(strict_types=1);

namespace App\VaultBrowser;

use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;
use Tempest\Router\ResponseSender;

/**
 * Install the download-aware response sender as the framework response sender.
 */
final readonly class BrowserResponseSenderInitializer implements Initializer
{
    /**
     * Resolve the singleton sender that delegates ordinary responses and streams verified files.
     */
    #[Singleton]
    public function initialize(Container $container): ResponseSender
    {
        return $container->get(BrowserResponseSender::class);
    }
}
