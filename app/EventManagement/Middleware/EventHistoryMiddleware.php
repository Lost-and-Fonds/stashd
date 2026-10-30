<?php

declare(strict_types=1);

namespace App\EventManagement\Middleware;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\Contracts\EventHistoryRecorder;
use ReflectionClass;
use Tempest\Discovery\SkipDiscovery;
use Tempest\EventBus\EventBusMiddleware;
use Tempest\EventBus\EventBusMiddlewareCallable;

/**
 * Routes explicitly classified events to a future operational history recorder.
 * Discovery is disabled until Core has a recorder with a stated commit boundary.
 */
#[SkipDiscovery]
final readonly class EventHistoryMiddleware implements EventBusMiddleware
{
    /**
     * Connects the middleware to an explicitly supplied recorder.
     * There is intentionally no production recorder binding yet.
     */
    public function __construct(
        private EventHistoryRecorder $recorder,
    ) {}

    /**
     * Continues normal dispatch and records only explicitly marked event classes.
     * The call does not make event delivery transactionally consistent.
     */
    public function __invoke(string|object $event, EventBusMiddlewareCallable $next): void
    {
        $next($event);

        if (! is_object($event) || ! new ReflectionClass($event)->getAttributes(DurableHistory::class)) {
            return;
        }

        $this->recorder->record($event);
    }
}
