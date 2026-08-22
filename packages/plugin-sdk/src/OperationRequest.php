<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class OperationRequest
{
    /**
     * @param  list<Setting>  $settings
     * @param  list<Setting>  $payload
     */
    public function __construct(public string $name, public array $settings = [], public array $payload = []) {}
}
