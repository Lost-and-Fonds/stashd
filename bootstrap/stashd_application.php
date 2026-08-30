<?php

declare(strict_types=1);

use Tempest\Core\Tempest;
use Tempest\Router\HttpApplication;
use Tempest\Router\WorkerModeApplication;

require_once __DIR__ . '/tempest_internal_storage.php';

function stashd_application_root(): string
{
    return dirname(__DIR__);
}

function stashd_classic_application(): HttpApplication
{
    return HttpApplication::boot(
        stashd_application_root(),
        [],
        tempest_internal_storage(),
    );
}

function stashd_worker_application(): WorkerModeApplication
{
    return Tempest::boot(
        root: stashd_application_root(),
        internalStorage: tempest_internal_storage(),
        longRunning: true,
    )->get(WorkerModeApplication::class);
}
