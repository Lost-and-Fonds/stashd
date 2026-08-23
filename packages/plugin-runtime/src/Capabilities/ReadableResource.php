<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Capabilities;

interface ReadableResource
{
    public function read(int $maximumBytes = 65536): string;

    public function isEof(): bool;

    public function close(): void;
}
