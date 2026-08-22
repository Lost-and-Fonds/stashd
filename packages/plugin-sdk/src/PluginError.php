<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class PluginError
{
    public function __construct(public string $message, public bool $retryable) {}
}
