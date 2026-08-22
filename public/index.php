<?php

declare(strict_types=1);

use Tempest\Router\HttpApplication;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../bootstrap/tempest_internal_storage.php';

HttpApplication::boot(__DIR__.'/..', [], tempest_internal_storage())->run();

exit();
