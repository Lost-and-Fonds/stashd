<?php

declare(strict_types=1);

$canary = @file_get_contents('/vault/DO_NOT_READ');
$network = @fsockopen('1.1.1.1', 80, $errorCode, $errorMessage, 0.2);
if (is_resource($network)) {
    fclose($network);
}
@file_put_contents('/plugin/MUTATION_TEST', 'must fail');
file_put_contents('/staging/helper-report.json', json_encode([
    'vault' => $canary === false ? 'denied' : $canary,
    'network' => is_resource($network) ? 'allowed' : 'denied',
    'secret' => getenv('STASHD_SECRET') === false ? 'absent' : 'leaked',
], JSON_THROW_ON_ERROR));
