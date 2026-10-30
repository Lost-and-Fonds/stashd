<?php

declare(strict_types=1);

namespace Tests\Support\EventManagement;

use App\EventManagement\Contracts\PremisEventRecorder;

/**
 * Captures selected events for the middleware test without writing archival metadata.
 */
final class RecordingPremisEventRecorder implements PremisEventRecorder
{
    /** @var list<object> */
    public array $events = [];

    /**
     * Keeps a selected event for the classification assertion.
     */
    public function record(object $event): void
    {
        $this->events[] = $event;
    }
}
