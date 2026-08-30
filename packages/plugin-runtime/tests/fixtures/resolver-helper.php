<?php

declare(strict_types=1);

file_put_contents('/staging/resolver-present', is_file('/etc/resolv.conf') ? 'yes' : 'no');
