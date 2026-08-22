<?php

declare(strict_types=1);

require_once __DIR__ . '/../sdk/Sdk.php';
require_once __DIR__ . '/ExamplePlugin.php';

use Stashd\ExamplePlugin\ExampleEntrypoint;
use Stashd\PluginSdk\PluginContext;

return new ExampleEntrypoint(new PluginContext());
