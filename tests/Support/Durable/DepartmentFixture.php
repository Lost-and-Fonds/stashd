<?php

declare(strict_types=1);

namespace Tests\Support\Durable;

use App\Integration\OperationsConfig;
use App\Operations\Durable\DurableConnections;
use App\Operations\Durable\DurableRuntime;
use PDO;
use Tempest\Core\FrameworkKernel;
use Tempest\Discovery\DiscoveryLocation;

/**
 * Boot the real Tempest application against an isolated unprivileged PostgreSQL fixture.
 */
final class DepartmentFixture
{
    public PDO $admin;
    public string $name;
    public OperationsConfig $config;
    public \Tempest\Container\Container $container;
    public DurableConnections $connections;
    public DurableRuntime $runtime;

    /**
     * Provision only generated databases; workers never receive admin credentials.
     */
    public function __construct()
    {
        $admin = json_decode(file_get_contents(dirname(__DIR__, 3) . '/tmp/transaction-projection-admin.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->admin = new PDO($admin['dsn'], $admin['username'], $admin['password']);
        $this->name = 'stashd_tx_test_' . bin2hex(random_bytes(8));
        $password = bin2hex(random_bytes(24));
        $this->admin->exec('CREATE ROLE "' . $this->name . '" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD ' . $this->admin->quote($password));
        $this->admin->exec('CREATE DATABASE "' . $this->name . '" OWNER "' . $this->name . '"');
        $this->config = new OperationsConfig('pgsql:host=lerd-postgres;port=5432;dbname=' . $this->name, $this->name, $password);
        try {
            $this->container = self::boot($this->config);
            $this->connections = $this->container->get(DurableConnections::class);
            $this->container->get(\App\Integration\IntegrationConfigurations::class)->initialize();
            $this->runtime = $this->container->get(DurableRuntime::class);
            $this->connections->application->executeStatement('CREATE TABLE effects(id BIGINT GENERATED ALWAYS AS IDENTITY, assignment TEXT NOT NULL, value TEXT NOT NULL)');
        } catch (\Throwable $error) {
            $this->dispose();
            throw $error;
        }
    }

    /**
     * Reuse normal discovery and initialization in parent and fresh worker processes.
     */
    public static function boot(OperationsConfig $config): \Tempest\Container\Container
    {
        $kernel = FrameworkKernel::boot(dirname(__DIR__, 3), [new DiscoveryLocation('Tests\Support\Durable', __DIR__)]);
        $kernel->container->config($config);

        return $kernel->container;
    }

    /**
     * Drop only this fixture after workers have been stopped and reaped.
     */
    public function dispose(): void
    {
        $this->admin->exec('DROP DATABASE "' . $this->name . '" WITH (FORCE)');
        $this->admin->exec('DROP ROLE "' . $this->name . '"');
    }
}
