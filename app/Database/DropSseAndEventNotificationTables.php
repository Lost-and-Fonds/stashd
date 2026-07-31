<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;

/**
 * Both tables were pure SSE-transport plumbing for the old `/api/v1/events`
 * poll loop, replaced by FrankenPHP's embedded Mercure hub -- `sse_connections`
 * capped concurrent poll connections, `event_notifications` relayed the
 * events themselves. Neither held durable state (ActivityEventRecord remains
 * the durable audit log).
 */
final class DropSseAndEventNotificationTables implements MigratesUp
{
    public string $name = '2026_07_05_drop_sse_and_event_notification_tables';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement('DROP TABLE IF EXISTS `sse_connections`'),
            new MigrationSqlStatement('DROP TABLE IF EXISTS `event_notifications`'),
        );
    }
}
