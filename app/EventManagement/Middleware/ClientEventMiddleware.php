<?php

declare(strict_types=1);

namespace App\EventManagement\Middleware;

use App\EventManagement\Attributes\ClientVisible;
use App\EventManagement\Contracts\ClientEventPublisher;
use App\EventManagement\DTO\ClientEvent;
use BackedEnum;
use DateTimeInterface;
use ReflectionClass;
use Tempest\Discovery\SkipDiscovery;
use Tempest\EventBus\EventBusMiddleware;
use Tempest\EventBus\EventBusMiddlewareCallable;
use Throwable;
use UnexpectedValueException;

/**
 * Projects only allowlisted properties for a future client notification transport.
 * It stays inactive until commit-consistent delivery is available.
 */
#[SkipDiscovery]
final readonly class ClientEventMiddleware implements EventBusMiddleware
{
    /**
     * Connects the middleware to a future client transport.
     * There is intentionally no Mercure or SSE publisher binding yet.
     */
    public function __construct(
        private ClientEventPublisher $publisher,
    ) {}

    /**
     * Continues normal dispatch and publishes only explicitly projected events.
     * A publication failure cannot change the already established domain fact.
     */
    public function __invoke(string|object $event, EventBusMiddlewareCallable $next): void
    {
        $next($event);

        if (! is_object($event)) {
            return;
        }

        $reflection = new ReflectionClass($event);
        $attribute = $reflection->getAttributes(ClientVisible::class)[0] ?? null;
        if ($attribute === null) {
            return;
        }

        $visible = $attribute->newInstance();
        $fields = [];
        foreach ($visible->fields as $field) {
            if (! $reflection->hasProperty($field) || ! $reflection->getProperty($field)->isPublic()) {
                throw new UnexpectedValueException("Client-visible property {$field} is missing or not public.");
            }

            $value = $reflection->getProperty($field)->getValue($event);
            if ($value instanceof BackedEnum) {
                $value = $value->value;
            }

            if (! is_scalar($value)) {
                throw new UnexpectedValueException("Client-visible field {$field} must be scalar or a backed enum.");
            }

            $fields[$field] = $value;
        }

        if (! $reflection->hasProperty('metadata')) {
            throw new UnexpectedValueException('Client-visible event must expose standard EventMetadata.');
        }

        $metadata = $reflection->getProperty('metadata')->getValue($event);
        if (! $metadata instanceof \App\EventManagement\DTO\EventMetadata) {
            throw new UnexpectedValueException('Client-visible event must expose standard EventMetadata.');
        }

        try {
            $this->publisher->publish(new ClientEvent(
                topic: $visible->topic,
                eventId: $metadata->eventId,
                occurredAt: $metadata->occurredAt->format(DateTimeInterface::ATOM),
                fields: $fields,
            ));
        } catch (Throwable) {
            // Delivery is best-effort and cannot reverse a fact handlers already observed.
        }
    }
}
