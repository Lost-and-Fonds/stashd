<?php

declare(strict_types=1);

use App\Operations\JobRuntime;
use App\Operations\Jobs;
use App\Operations\Work;
use Tests\Support\Operations\LocalOperationsDriver;
use Tests\Support\Operations\ProbeExecutor;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

try {
    $request = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $descriptor = $request['descriptor'];
    $database = LocalOperationsDriver::connect($descriptor);
    foreach (['DSN' => $descriptor['dsn'], 'USERNAME' => $descriptor['username'], 'PASSWORD' => $descriptor['password'], 'SCHEMA' => $descriptor['schema']] as $key => $value) {
        putenv('STASHD_OPERATIONS_' . $key . '=' . $value);
        $_ENV['STASHD_OPERATIONS_' . $key] = $value;
    }

    $container = \Tempest\Core\Tempest::boot(root: dirname(__DIR__, 3));
    $container->singleton(\App\Operations\WorkExecutor::class, new ProbeExecutor($database));
    $runtime = $container->get(JobRuntime::class);
    $result = [];
    if ($request['action'] === 'initialize') {
        $container
            ->get(\Ecotone\Api\Messaging\ConfiguredMessagingSystem::class)
            ->getServiceFromContainer(\Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager::class)
            ->initializeAll();
    } elseif ($request['action'] === 'request') {
        $work = $request['work'];
        $result = ['job_id' => new Jobs($runtime)->request(new Work('acceptance.probe', $work['scope_id'], $work['mode'], $work['observation']), $work['priority'])];
    } elseif ($request['action'] === 'execute') {
        $container->get(\Ecotone\Api\Messaging\ConfiguredMessagingSystem::class)->run(
            'jobs',
            \Ecotone\Api\ExtensionObject\ExecutionPollingMetadata::createWithDefaults()
                ->withStopOnError(false)
                ->withHandledMessageLimit($request['limit'])
                ->withExecutionTimeLimitInMilliseconds(2000),
        );
    } else {
        throw new RuntimeException('Unsupported child action.');
    }

    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
