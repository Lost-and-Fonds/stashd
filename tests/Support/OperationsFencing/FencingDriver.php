<?php

declare(strict_types=1);

namespace Tests\Support\OperationsFencing;

/**
 * Adapt OPS-C03 acceptance actions to real Operations and guarded host operations.
 *
 * This test-only port does not prescribe a production implementation or grant
 * authority itself. All ownership and fencing decisions must come from Core.
 */
interface FencingDriver
{
    /**
     * Perform one observable action in an isolated persistent installation.
     *
     * A fresh driver instance is created for each call. Unsupported behaviour
     * must be marked INCOMPLETE, rather than faked through this adapter.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function run(string $action, array $arguments): array;
}
