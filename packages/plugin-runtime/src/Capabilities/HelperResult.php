<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Capabilities;

final readonly class HelperResult
{
    public function __construct(public int $exitCode, public string $stdout, public string $stderr) {}
}
