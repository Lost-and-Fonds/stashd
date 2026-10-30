<?php

declare(strict_types=1);

namespace Tests\Support\OperationsDelivery;

/**
 * Adapt the independently authored delivery contracts to real Stashd runtime actions.
 *
 * This test-only interface describes observable actions, not production classes.
 * Drivers must use persisted Jobs, actual Messenger transports, fresh processes,
 * and effects stored independently of the Job state.
 */
interface DurableDeliveryDriver
{
    /**
     * Perform an observable action inside one disposable installation.
     *
     * The workspace is the same across calls, but driver objects are recreated.
     * Unsupported application capabilities must report INCOMPLETE, not success.
     * Fault controls must affect the real database, relay, or worker boundary.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function run(string $action, array $arguments): array;
}
