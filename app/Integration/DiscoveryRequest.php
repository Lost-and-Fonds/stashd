<?php

namespace App\Integration;

/**
 * Fix the host-owned Input identity, options and bounds for one discovery invocation.
 */
final readonly class DiscoveryRequest
{
    /**
     * Bind complete-enumeration intent and the last durably acknowledged continuation.
     *
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $input_id,
        public string $intent,
        public array $options,
        public ?string $continuation,
        public ?string $refresh_state,
        public int $maximum_items_per_batch,
    ) {}
}
