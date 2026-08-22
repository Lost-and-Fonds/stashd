<?php

declare(strict_types=1);

use Tempest\Database\Config\PostgresConfig;
use Tempest\Database\Config\SQLiteConfig;

use function Tempest\env;
use function Tempest\root_path;

$resolvePath = static function (string $path): string {
    if (str_starts_with($path, '/') || str_starts_with($path, ':')) {
        return $path;
    }

    return root_path($path);
};

$stringEnv = static function (string $key, string $default): string {
    $value = env($key, $default);

    if (! is_string($value)) {
        throw new InvalidArgumentException("Environment variable [{$key}] must be a string.");
    }

    return $value;
};

$connection = strtolower($stringEnv('DB_CONNECTION', 'pgsql'));

if ($connection === 'pgsql') {
    return new PostgresConfig(
        host: $stringEnv('DB_HOST', '127.0.0.1'),
        port: $stringEnv('DB_PORT', '5432'),
        username: $stringEnv('DB_USERNAME', 'postgres'),
        password: $stringEnv('DB_PASSWORD', ''),
        database: $stringEnv('DB_DATABASE', 'stashd'),
    );
}

if ($connection !== 'sqlite') {
    throw new InvalidArgumentException("Unsupported DB_CONNECTION [{$connection}]. Expected sqlite or pgsql.");
}

$dataPath = $resolvePath($stringEnv('STASHD_DATA_PATH', $stringEnv('DATA_PATH', 'data')));
$databasePath = env('DB_DATABASE');

if ($databasePath === null) {
    $databasePath = rtrim($dataPath, '/') . '/stashd.sqlite';
} elseif (! is_string($databasePath)) {
    throw new InvalidArgumentException('Environment variable [DB_DATABASE] must be a string.');
}

if (! str_starts_with($databasePath, '/') && ! str_starts_with($databasePath, ':')) {
    $databasePath = rtrim($dataPath, '/') . '/' . ltrim($databasePath, '/');
}

return new SQLiteConfig(path: $databasePath);
