<?php

declare(strict_types=1);

namespace App\Config;

final readonly class TrustedProxyConfig
{
    /** @param list<string> $addresses */
    public function __construct(
        public array $addresses = [],
    ) {}

    public function trusts(string $address): bool
    {
        return in_array($address, $this->addresses, true);
    }
}
