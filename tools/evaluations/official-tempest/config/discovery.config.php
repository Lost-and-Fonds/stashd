<?php

use App\Operations\DeliverySchema;
use App\Operations\JobRuntimeInitializer;
use App\Operations\OperationsConnectionInitializer;
use Tempest\Discovery\DiscoveryConfig;

// The isolated official installation must not resolve the candidate's bridge.
$config = new DiscoveryConfig()->skipClasses(OperationsConnectionInitializer::class, JobRuntimeInitializer::class, DeliverySchema::class);
if (getenv('ECOTONE_INJECTED_CONNECTION') !== '1') {
    $config->skipClasses(\Evaluation\Official\InjectedConnectionInitializer::class);
}

return $config;
