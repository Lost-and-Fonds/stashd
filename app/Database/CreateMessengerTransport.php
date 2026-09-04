<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\RawStatement;

final class CreateMessengerTransport implements MigratesUp
{
    public string $name = '2026_09_04_create_messenger_transport';

    public function up(): QueryStatement
    {
        return new RawStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS messenger_messages (
                id BIGSERIAL NOT NULL,
                body TEXT NOT NULL,
                headers TEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
                available_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
                delivered_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
    }
}
