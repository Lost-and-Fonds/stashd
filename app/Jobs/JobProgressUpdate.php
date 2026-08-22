<?php

declare(strict_types=1);

namespace App\Jobs;

final readonly class JobProgressUpdate
{
    private function __construct(
        public ?int $current,
        public ?int $total,
        public ?float $percent,
        public string $label,
        public ?int $etaSeconds = null,
        public ?float $rate = null,
    ) {}

    public static function ofSteps(int $current, int $total, string $label): self
    {
        return new self(
            current: $current,
            total: $total,
            percent: $total > 0 ? round($current / $total * 100, 2) : 0.0,
            label: $label,
        );
    }

    public static function ofPercent(float $percent, string $label, ?int $etaSeconds = null, ?float $rate = null): self
    {
        return new self(
            current: null,
            total: null,
            percent: $percent,
            label: $label,
            etaSeconds: $etaSeconds,
            rate: $rate,
        );
    }

    public static function indeterminate(string $label): self
    {
        return new self(
            current: null,
            total: null,
            percent: null,
            label: $label,
        );
    }
}
