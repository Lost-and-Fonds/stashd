<?php

declare(strict_types=1);

namespace Stashd\PluginSdk\Native;

use Closure;
use Stashd\PluginSdk\Logger;

final readonly class NativeLogger implements Logger
{
    /** @param callable(string,array<string,mixed>):array<string,mixed> $call */
    public function __construct(private Closure $call) {}

    public function info(string $message): void
    {
        ($this->call)('event.log', ['level' => 'info', 'message' => $message]);
    }

    public function error(string $message): void
    {
        ($this->call)('event.log', ['level' => 'error', 'message' => $message]);
    }
}
