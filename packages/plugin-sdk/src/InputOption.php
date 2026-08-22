<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class InputOption
{
    public function __construct(public string $key, public OptionValue $value) {}
}
