<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\TrustedProxyConfig;
use Tempest\Http\Request;

final readonly class ClientAddressResolver
{
    public function __construct(
        private TrustedProxyConfig $proxies,
    ) {}

    public function resolve(Request $request): string
    {
        $peer = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';

        if (! $this->proxies->trusts($peer)) {
            return $peer;
        }

        $forwarded = $request->headers->get('X-Forwarded-For');
        $client = $forwarded === null ? null : trim(explode(',', $forwarded)[0]);

        return $client !== null && filter_var($client, FILTER_VALIDATE_IP) !== false ? $client : $peer;
    }
}
