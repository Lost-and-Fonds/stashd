<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

interface HttpClient
{
    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $credential = null): HttpResponse;
}
