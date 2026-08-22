<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

interface ReadableResource
{
    public function read(int $maximumBytes = 65536): string;

    public function isEof(): bool;

    public function close(): void;
}
