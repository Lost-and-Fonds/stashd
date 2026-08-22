<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final class NullLogger implements Logger
{
    public function info(string $message): void {}

    public function error(string $message): void {}
}
