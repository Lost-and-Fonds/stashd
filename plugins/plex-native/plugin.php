<?php

declare(strict_types=1);

require_once '/sdk/bootstrap.php';
require_once __DIR__ . '/src/PlexBroadcast.php';

(new Stashd\PluginSdk\Native\NativePluginServer(new PlexNative\PlexBroadcast()))->run();
