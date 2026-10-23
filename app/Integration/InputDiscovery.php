<?php

namespace App\Integration;

use App\Integration\DTO\DiscoveryRequest;
use Closure;

/**
 * Let an Input adapter send small batches of found files to Core for saving.
 *
 * The arrays follow the plugin-api Input format without requiring a plugin runtime.
 * Core does not interpret adapter-specific IDs, references, metadata or scan positions.
 * Only Core's callback may accept a batch and save its files and progress together.
 *
 * @phpstan-type Item array{id: string, reference: string, source: ?array, size_bytes: ?int, size_estimated: bool, metadata: list<array{schema: string, json: string}>}
 * @phpstan-type Batch array{items: list<Item>, progress: array}
 */
interface InputDiscovery
{
    /**
     * Send batches and return only after Core has saved the finished result.
     *
     * An error does not undo earlier saved batches. A retry receives the last saved
     * scan position and must work in a fresh process, without relying on memory.
     *
     * @param Closure(Batch): void $commit Core's save callback; throws if validation or storage fails.
     */
    public function discover(DiscoveryRequest $request, Closure $commit): void;
}
