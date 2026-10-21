<?php

declare(strict_types=1);

namespace App\VaultBrowser;

use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;
use Tempest\Router\ResponseSender;

final readonly class BrowserResponseSenderInitializer implements Initializer
{
    #[Singleton]
    public function initialize(Container $container): ResponseSender
    {
        return $container->get(BrowserResponseSender::class);
    }
}
