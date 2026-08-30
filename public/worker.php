<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap/stashd_application.php';

$application = stashd_worker_application();
$maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 100);

$handler = static function () use ($application): void {
    try {
        $application->run();
    } catch (Throwable $e) {
        // A lifecycle/reset failure leaves application state untrusted. Do not
        // return to FrankenPHP's request loop: a non-zero exit lets it replace
        // this worker instead of reusing dirty state for the next request.
        error_log((string) $e);
        exit(1);
    }
};

for ($request = 0; ! $maxRequests || $request < $maxRequests; $request++) {
    $keepRunning = frankenphp_handle_request($handler);

    gc_collect_cycles();

    if (! $keepRunning) {
        break;
    }
}
