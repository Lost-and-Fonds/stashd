<?php

declare(strict_types=1);

namespace Evaluation\Official;

/**
 * Carry one diagnostic value through the official asynchronous messaging integration.
 */
final readonly class BridgePing
{
    /**
     * Keep a stable value for independent PostgreSQL observation after consumption.
     */
    public function __construct(
        public string $value,
    ) {}
}
