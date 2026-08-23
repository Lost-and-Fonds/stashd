<?php

declare(strict_types=1);

namespace App\System\Wiring;

use App\Plugins\ExternalInputPluginRegistry;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

final class ExternalInputPluginRegistryInitializer implements Initializer
{
    public function initialize(Container $container): ExternalInputPluginRegistry
    {
        return new ExternalInputPluginRegistry();
    }
}
