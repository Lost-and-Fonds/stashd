<?php

declare(strict_types=1);

use App\Operations\LeaseConfig;

use function Tempest\env;

return new LeaseConfig((int) env('STASHD_EXECUTION_LEASE_MS', default: 60_000));
