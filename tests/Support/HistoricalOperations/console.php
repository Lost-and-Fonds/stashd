<?php

declare(strict_types=1);

use Tempest\Console\ConsoleApplication;
use Tempest\Discovery\DiscoveryLocation;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

ConsoleApplication::boot(
    root: dirname(__DIR__, 3),
    discoveryLocations: [new DiscoveryLocation('Tests\\Support\\HistoricalOperations', __DIR__)],
)->run();
