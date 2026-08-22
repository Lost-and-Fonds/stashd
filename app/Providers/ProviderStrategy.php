<?php

declare(strict_types=1);

namespace App\Providers;

final readonly class ProviderStrategy
{
    public function __construct(
        public string $key,
        public StrategyPurpose $purpose,
        public StrategyCost $cost,
        public bool $requiresAuth = false,
        public bool $supportsIncremental = false,
        public bool $supportsBackfill = false,
        public int $priority = 100,
    ) {}
}
