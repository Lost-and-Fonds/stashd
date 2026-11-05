<?php

declare(strict_types=1);

// Tag the real consumer's PostgreSQL sessions so independent lock samples name its OS process.
putenv('PGAPPNAME=stashd-filesystem-worker:' . getmypid());
require dirname(__DIR__, 3) . '/tempest';
