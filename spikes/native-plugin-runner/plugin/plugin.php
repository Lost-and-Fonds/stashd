<?php

declare(strict_types=1);

$attempt = static function (callable $callback): string|bool {
    try {
        $value = $callback();
        return is_string($value) ? $value : ($value === false ? false : 'readable');
    } catch (Throwable) {
        return false;
    }
};

$report = [
    'vault' => $attempt(static fn (): string|false => @file_get_contents('/vault/DO_NOT_READ')),
    'outer_app' => $attempt(static fn (): string|false => @file_get_contents('/app/secret.txt')),
    'outer_data' => $attempt(static fn (): string|false => @file_get_contents('/data/database.env')),
    'proc' => is_readable('/proc/self/status'),
    'database_env' => getenv('STASHD_DATABASE_URL') ?: null,
    'encryption_env' => getenv('STASHD_ENCRYPTION_KEY') ?: null,
    'plugin_mutation' => @file_put_contents('/plugin/MUTATION_TEST', 'must fail') !== false,
    'staging_write' => @file_put_contents('/staging/allowed.txt', "staged output\n") !== false,
    'tmp_write' => @file_put_contents('/tmp/allowed.txt', "private tmp\n") !== false,
    'direct_network' => @fsockopen('1.1.1.1', 80, $errorNumber, $error, 0.25) !== false,
];

file_put_contents('/staging/report.json', json_encode($report, JSON_THROW_ON_ERROR));
