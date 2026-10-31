<?php

use Tempest\Discovery\DiscoveryConfig;

// Ecotone loads its modules and drivers itself; Tempest only needs its integration hooks.
return new DiscoveryConfig()->skipPaths(
    dirname(__DIR__, 2) . '/vendor/ecotone/tempest/src/Config',
    dirname(__DIR__, 2) . '/vendor/ecotone/tempest/Api',
);
