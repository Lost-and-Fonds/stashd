<?php

declare(strict_types=1);

namespace App\EventManagement\Contracts;

/**
 * Defines the future boundary for preserving PREMIS-relevant event evidence.
 * No PREMIS schema, serializer or persistence guarantee is implied here.
 */
interface PremisEventRecorder
{
    /**
     * Handles a classified event using the application's future archival integration.
     * The implementation must not claim archival recording before its own durable boundary.
     */
    public function record(object $event): void;
}
