<?php

declare(strict_types=1);

use Tempest\Database\Config\PostgresConfig;

use function Tempest\env;

$stringEnv = static function (string $key, string $default): string {
    $value = env($key, $default);

    if (! is_string($value)) {
        throw new InvalidArgumentException("Environment variable [{$key}] must be a string.");
    }

    return $value;
};

return new PostgresConfig(
    host: $stringEnv('DB_HOST', '127.0.0.1'),
    port: $stringEnv('DB_PORT', '5432'),
    username: $stringEnv('DB_USERNAME', 'postgres'),
    password: $stringEnv('DB_PASSWORD', ''),
    database: $stringEnv('DB_DATABASE', 'stashd'),
);
