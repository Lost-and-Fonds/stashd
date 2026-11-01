<?php

declare(strict_types=1);

namespace App\Operations\Persistence;

use App\Integration\IntegrationConfigurations;
use App\Operations\Durable\DurableConnections;
use App\Operations\Durable\DurableRuntime;
use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;
use Tempest\Container\Container;

/**
 * Initialize tracked domain migrations before opening Durable's upstream stores.
 */
final readonly class OperationsSetupCommand
{
    /**
     * Avoid resolving database-backed worker services before their schema exists.
     */
    public function __construct(
        private Container $container,
        private Console $console,
    ) {}

    /**
     * Reuse the existing tracked Tempest setup and upstream queue/journal schema owners.
     */
    #[ConsoleCommand('operations:install')]
    public function install(): void
    {
        $this->container->get(DurableConnections::class);
        $this->container->get(IntegrationConfigurations::class)->initialize();
        $this->container->get(DurableRuntime::class);
        $this->console->success('Operations and upstream delivery schemas initialized.');
    }
}
