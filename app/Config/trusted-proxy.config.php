<?php

declare(strict_types=1);

use App\Config\TrustedProxyConfig;

use function Tempest\env;
use function Tempest\Support\str;

$rawAddresses = env('STASHD_TRUSTED_PROXY_ADDRESSES', '');
$addresses = array_values(array_filter(array_map(
    static fn(string $address): string => str($address)->trim()->toString(),
    explode(',', is_string($rawAddresses) ? $rawAddresses : ''),
)));

return new TrustedProxyConfig($addresses);
