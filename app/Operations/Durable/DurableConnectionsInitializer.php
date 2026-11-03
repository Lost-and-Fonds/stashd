<?php

declare(strict_types=1);

namespace App\Operations\Durable;

use App\Integration\OperationsConfig;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;

/**
 * Open separate lazy DBAL sessions from the existing Operations configuration.
 */
final class DurableConnectionsInitializer implements Initializer
{
    /**
     * Bind Tempest persistence to the application session before resolving models.
     */
    #[Singleton]
    public function initialize(Container $container): DurableConnections
    {
        $config = $container->get(OperationsConfig::class);
        if (! str_starts_with($config->dsn, 'pgsql:') || $config->username === '') {
            throw new InvalidArgumentException('Explicit PostgreSQL Operations credentials are required.');
        }

        $parts = [];
        foreach (explode(';', substr($config->dsn, 6)) as $part) {
            if ($part === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);
            if (! in_array($key, ['host', 'port', 'dbname', 'sslmode', 'sslrootcert', 'sslcert', 'sslkey'], true) || $value === null) {
                throw new InvalidArgumentException('Unsupported PostgreSQL Operations DSN.');
            }

            $parts[$key] = $value;
        }

        $parameters = [...$parts, 'driver' => 'pdo_pgsql', 'user' => $config->username, 'password' => $config->password];
        $open = static function () use ($parameters, $config) {
            $db = DriverManager::getConnection($parameters);
            $db->executeStatement('SET search_path TO "' . $config->schema . '"');

            return $db;
        };
        $connections = new DurableConnections($open(), $open(), $open());
        $container->config($config);

        return $connections;
    }
}
