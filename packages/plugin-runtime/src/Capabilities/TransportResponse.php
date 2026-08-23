<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Capabilities;

final readonly class TransportResponse
{
    /**
     * @param  array<string, string>  $headers
     * @param  iterable<string>  $chunks
     */
    public function __construct(public int $status, public array $headers, public iterable $chunks) {}
}
