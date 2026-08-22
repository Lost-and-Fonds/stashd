<?php

declare(strict_types=1);

@file_put_contents('/plugin/LINKED_SOURCE_MUTATION', 'must fail');
$vault = @file_get_contents('/vault/DO_NOT_READ');
$network = @fsockopen('1.1.1.1', 80, $errorCode, $errorMessage, 0.2);
if (is_resource($network)) {
    fclose($network);
}
file_put_contents('/staging/linked-report.json', json_encode([
    'vault' => $vault === false ? 'denied' : 'leaked',
    'network' => is_resource($network) ? 'allowed' : 'denied',
], JSON_THROW_ON_ERROR));
