<?php

declare(strict_types=1);

namespace App\EventManagement\DTO;

/**
 * Summarizes catalogue completeness and the separately observed event coverage stages.
 * Unreferenced definitions are reported but are not catalogue errors.
 */
final readonly class CatalogueAuditReport
{
    /**
     * Holds catalogue differences, contract violations and coverage observations.
     * Defined is checked by reflection. Null for other stages means unmeasured. True or false requires explicit integration evidence.
     *
     * @param list<string> $missingClasses
     * @param list<string> $unexpectedClasses
     * @param list<string> $contractViolations
     * @param array<string, array{defined: bool, emitted: ?bool, verified: ?bool, consumed: ?bool}> $coverage
     */
    public function __construct(
        public array $missingClasses,
        public array $unexpectedClasses,
        public array $contractViolations,
        public array $coverage,
    ) {}

    /**
     * Returns whether the declared catalogue and event contracts are complete.
     * An event with no emitter does not make the catalogue incomplete.
     */
    public function isComplete(): bool
    {
        return $this->missingClasses === [] && $this->unexpectedClasses === [] && $this->contractViolations === [];
    }
}
