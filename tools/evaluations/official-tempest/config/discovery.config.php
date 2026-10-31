<?php

use App\Operations\DeliverySchema;
use App\Operations\JobRuntimeInitializer;
use App\Operations\OperationsConnectionInitializer;
use Tempest\Discovery\DiscoveryConfig;

// The isolated official installation must not resolve the candidate's bridge.
return new DiscoveryConfig()->skipClasses(OperationsConnectionInitializer::class, JobRuntimeInitializer::class, DeliverySchema::class);
