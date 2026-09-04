<?php

declare(strict_types=1);

namespace App\Broadcasts;

final readonly class BroadcastLifecycleResult
{
    /**
     * @param  array<string, mixed>|null  $plan
     * @param  array<string, mixed>|null  $publish
     * @param  array<string, mixed>|null  $verify
     * @param  array<string, mixed>|null  $prune
     */
    public function __construct(
        public ?array $plan = null,
        public ?array $publish = null,
        public ?array $verify = null,
        public ?array $prune = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'plan' => $this->plan,
            'publish' => $this->publish,
            'verify' => $this->verify,
            'prune' => $this->prune,
        ], static fn($value): bool => $value !== null);
    }
}
