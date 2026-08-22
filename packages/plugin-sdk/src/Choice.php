<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class Choice
{
    public function __construct(public string $value, public string $label) {}
}
