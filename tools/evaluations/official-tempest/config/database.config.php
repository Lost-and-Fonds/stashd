<?php

use App\Integration\OperationsConnectionConfig;

return new OperationsConnectionConfig(require dirname(__DIR__, 4) . '/app/Integration/OperationsConfig.config.php');
