<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class Setting
{
    public function __construct(public string $key, public OptionValue $value) {}
}
