<?php

declare(strict_types=1);

namespace App\EventManagement\Contracts;

use App\EventManagement\DTO\ClientEvent;

/**
 * Defines the future boundary for publishing explicitly projected client events.
 * Notification delivery is best-effort presentation, not permanent history.
 */
interface ClientEventPublisher
{
    /**
     * Publishes one allowlisted projection through a client transport.
     * A transport failure must not reverse an already established domain fact.
     */
    public function publish(ClientEvent $event): void;
}
