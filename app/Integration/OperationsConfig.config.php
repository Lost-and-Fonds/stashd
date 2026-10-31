<?php

use App\Integration\OperationsConfig;

use function Tempest\env;

return new OperationsConfig(
    dsn: env('STASHD_OPERATIONS_DSN', default: ''),
    username: env('STASHD_OPERATIONS_USERNAME', default: ''),
    password: env('STASHD_OPERATIONS_PASSWORD', default: ''),
    schema: env('STASHD_OPERATIONS_SCHEMA', default: 'stashd_operations'),
);
