<?php

declare(strict_types=1);

use App\Config\StashdConfig;

use function Tempest\env;
use function Tempest\root_path;

$resolvePath = static function (string $path): string {
    if (str_starts_with($path, '/') || str_starts_with($path, ':')) {
        return $path;
    }

    return root_path($path);
};

$envString = static function (string $key, string $default): string {
    $value = env($key, $default);

    return is_string($value) ? $value : $default;
};

return new StashdConfig(
    dataPath: $resolvePath($envString('STASHD_DATA_PATH', $envString('DATA_PATH', 'data'))),
    mediaPath: $resolvePath($envString('STASHD_MEDIA_PATH', $envString('MEDIA_PATH', 'media'))),
    publicUrl: $envString('STASHD_PUBLIC_URL', $envString('BASE_URI', 'http://localhost:8474')),
    logFormat: $envString('STASHD_LOG_FORMAT', 'text'),
    puid: (int) $envString('PUID', '1000'),
    pgid: (int) $envString('PGID', '1000'),
    umask: $envString('UMASK', '0022'),
    httpPort: $envString('STASHD_HTTP_PORT', '8474'),
);
