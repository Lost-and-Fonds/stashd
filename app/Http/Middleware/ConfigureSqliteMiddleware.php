<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\System\Boot\SqliteConfigurator;
use Tempest\Database\Config\DatabaseConfig;
use Tempest\Http\Request;
use Tempest\Http\Response;
use Tempest\Router\HttpMiddleware;
use Tempest\Router\HttpMiddlewareCallable;
use Tempest\Support\Priority;

/**
 * busy_timeout and foreign_keys are per-connection SQLite pragmas.
 * stashd:boot sets them on its own throwaway CLI connection at container
 * start, which doesn't carry over: classic-mode PHP opens a fresh connection
 * every request (no long-lived worker to configure once, unlike the old
 * RoadRunner bridge), so without this they'd silently be off/0ms on every
 * web request -- FK violations pass silently and SQLITE_BUSY surfaces as
 * "not found" under write contention. Priority forces this ahead of every
 * other middleware (including RequireAuthMiddleware, which queries the DB).
 */
#[Priority(Priority::FRAMEWORK - 30)]
final readonly class ConfigureSqliteMiddleware implements HttpMiddleware
{
    public function __construct(
        private SqliteConfigurator $sqlite,
        private DatabaseConfig $databaseConfig,
    ) {
    }

    public function __invoke(Request $request, HttpMiddlewareCallable $next): Response
    {
        $this->sqlite->configure($this->databaseConfig);

        return $next($request);
    }
}
