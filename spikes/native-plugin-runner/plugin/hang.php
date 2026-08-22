<?php

declare(strict_types=1);

file_put_contents('/staging/started', "started\n");
sleep(5);
file_put_contents('/staging/alive', "still alive\n");
