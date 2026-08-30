<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap/stashd_application.php';

$application = stashd_worker_application();
$maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 100);

$handler = static function () use ($application): void {
    $application->run();
};

for ($request = 0; ! $maxRequests || $request < $maxRequests; $request++) {
    $keepRunning = frankenphp_handle_request($handler);

    gc_collect_cycles();

    if (! $keepRunning) {
        break;
    }
}
