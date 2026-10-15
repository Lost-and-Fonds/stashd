<?php

declare(strict_types=1);

namespace Tests\Support\RecordsAndArchives;

/**
 * Maps the test-only acceptance vocabulary to real Stashd behaviour.
 *
 * This is not a suggested production interface. A concrete implementation
 * must use the actual application, real storage and durable records, never
 * return invented test results or a substitute in-memory Vault.
 */
interface ArchivesDriver
{
    /**
     * Run an observed archival operation within an isolated test workspace.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function run(string $operation, array $arguments): array;
}
