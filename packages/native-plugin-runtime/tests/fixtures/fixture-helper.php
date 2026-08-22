<?php

declare(strict_types=1);

@file_put_contents('/plugin/HELPER_MUTATION', 'must fail');
file_put_contents('/staging/helper-ran', 'ok');
$network = @fsockopen('1.1.1.1', 80, $errno, $error, 0.2);
if (is_resource($network)) {
    fclose($network);
    file_put_contents('/staging/helper-network', 'unexpected');
}
$vault = @file_get_contents('/vault/DO_NOT_READ');
file_put_contents('/staging/helper-report.json', json_encode([
    'vault' => $vault === false ? 'denied' : 'visible',
    'network' => is_file('/staging/helper-network') ? 'visible' : 'denied',
    'secret' => getenv('STASHD_SECRET') === false ? 'absent' : 'visible',
], JSON_THROW_ON_ERROR));
