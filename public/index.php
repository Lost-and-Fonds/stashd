<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../bootstrap/stashd_application.php';

stashd_classic_application()->run();

exit();
