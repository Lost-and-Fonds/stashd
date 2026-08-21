<?php

declare(strict_types=1);

namespace App\Providers;

final readonly class StrategySelectionOptions
{
    public function __construct(
        public bool $allowLastResort = false,
        /**
         * Picks the highest-cost available strategy instead of the lowest-cost
         * one. Cost is used here as a capability proxy: a pricier strategy is
         * registered that way because it does more (e.g. full enumeration vs.
         * a cheap recent-items sample), so "highest cost available" means
         * "most capable available" for callers that want a full backfill
         * rather than a cheap preview.
         */
        public bool $preferHighestCapability = false,
        public bool $preferIncremental = false,
    ) {
    }
}
