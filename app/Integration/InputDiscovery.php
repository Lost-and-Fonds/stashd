<?php

namespace App\Integration;

use Closure;

/**
 * Discover through bounded acknowledged batches using canonical Input semantics.
 *
 * PHP arrays mirror plugin-api Input value records, not an RPC runtime. IDs,
 * references, metadata and continuation remain component-owned opaque values.
 * Only the host callback may durably accept Items and their progress together.
 *
 * @phpstan-type Item array{id: string, reference: string, source: ?array, size_bytes: ?int, size_estimated: bool, metadata: list<array{schema: string, json: string}>}
 * @phpstan-type Batch array{items: list<Item>, progress: array}
 */
interface InputDiscovery
{
    /**
     * Emit batches and return normally only after an acknowledged terminal finish.
     *
     * An exception does not undo earlier acknowledgments. The next invocation
     * receives the last committed continuation; no process-local state is required.
     *
     * @param Closure(Batch): void $commit Host acknowledgment, throwing on rejection or storage failure.
     */
    public function discover(DiscoveryRequest $request, Closure $commit): void;
}
