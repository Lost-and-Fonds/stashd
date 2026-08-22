<?php

declare(strict_types=1);

namespace Stashd\NativeRuntime\Capabilities;

final readonly class HelperGrant
{
    public function __construct(public string $name, public string $relativeExecutable) {}
}
