<?php

namespace App\Integration\DTO;

/**
 * Give an Input adapter the settings and saved position for one scan attempt.
 *
 * Core chooses the Input ID, scan intent and maximum batch size.
 */
final readonly class DiscoveryRequest
{
    /**
     * Carry the requested scan settings and the last position saved in the database.
     *
     * The adapter must honor the batch limit and resume from the supplied position.
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
