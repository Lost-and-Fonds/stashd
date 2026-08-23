<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Capabilities;

final readonly class HelperGrant
{
    public function __construct(public string $name, public string $relativeExecutable) {}
}
