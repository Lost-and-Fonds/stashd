<?php

declare(strict_types=1);

namespace App\Jobs;

use Doctrine\DBAL\DriverManager;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\PostgreSqlConnection;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Tempest\Container\Singleton;

#[Singleton]
final class MessengerTransportRegistry
{
    /** @var array<string, DoctrineTransport> */
    private array $transports = [];

    public function get(string $name): DoctrineTransport
    {
        return $this->transports[$name] ??= $this->create($name);
    }

    /** @return array<string, DoctrineTransport> */
    public function all(): array
    {
        return [
            'interactive' => $this->get('interactive'),
            'background' => $this->get('background'),
            'failed' => $this->get('failed'),
        ];
    }

    private function create(string $name): DoctrineTransport
    {
        $dbal = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 5432),
            'user' => getenv('DB_USERNAME') ?: 'postgres',
            'password' => getenv('DB_PASSWORD') ?: '',
            'dbname' => getenv('DB_DATABASE') ?: 'stashd',
        ]);
        $connection = new PostgreSqlConnection([
            'table_name' => 'messenger_messages',
            'queue_name' => $name,
            'auto_setup' => true,
            // Long jobs stay leased while the worker sends Messenger keepalives.
            // A missing keepalive for 15 minutes is the stall/redelivery signal.
            'redeliver_timeout' => 900,
        ], $dbal);
        $connection->setup();

        return new DoctrineTransport($connection, new PhpSerializer());
    }
}
