<?php

declare(strict_types=1);

namespace App\EventManagement\Middleware;

use App\EventManagement\Attributes\PremisSignificant;
use App\EventManagement\Contracts\PremisEventRecorder;
use ReflectionClass;
use Tempest\Discovery\SkipDiscovery;
use Tempest\EventBus\EventBusMiddleware;
use Tempest\EventBus\EventBusMiddlewareCallable;

/**
 * Selects PREMIS-relevant events for a future archival recorder.
 * It remains undiscovered until a real preservation metadata integration exists.
 */
#[SkipDiscovery]
final readonly class PremisEventMiddleware implements EventBusMiddleware
{
    /**
     * Connects the middleware to an explicitly supplied archival recorder.
     * No recorder is registered in the application today.
     */
    public function __construct(
        private PremisEventRecorder $recorder,
    ) {}

    /**
     * Continues ordinary dispatch and forwards only PREMIS-marked event objects.
     * Forwarding does not claim that PREMIS evidence was durably recorded.
     */
    public function __invoke(string|object $event, EventBusMiddlewareCallable $next): void
    {
        $next($event);

        if (! is_object($event) || ! new ReflectionClass($event)->getAttributes(PremisSignificant::class)) {
            return;
        }

        $this->recorder->record($event);
    }
}
