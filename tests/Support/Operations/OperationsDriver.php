<?php

declare(strict_types=1);

namespace Tests\Support\Operations;

/**
 * Translates acceptance-test actions into real Stashd Operations behaviour.
 *
 * This is a test-only port, not a proposed application interface. A driver
 * must use actual persisted Jobs, transports, workers and execution effects.
 */
interface OperationsDriver
{
    /**
     * Perform one externally observable action in a disposable installation.
     *
     * The workspace identifies the same isolated installation across calls.
     * Unsupported application capabilities must raise IncompleteTestError;
     * drivers must never invent a successful Job or execution receipt.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function run(string $action, array $arguments): array;
}
