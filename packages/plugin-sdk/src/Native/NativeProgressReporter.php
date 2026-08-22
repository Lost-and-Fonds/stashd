<?php

declare(strict_types=1);

namespace Stashd\PluginSdk\Native;

use Closure;
use Stashd\PluginSdk\ProgressReporter;

final readonly class NativeProgressReporter implements ProgressReporter
{
    /** @param callable(string,array<string,mixed>):array<string,mixed> $call */
    public function __construct(private Closure $call) {}

    public function report(string $stage): void
    {
        ($this->call)('event.progress', ['stage' => $stage]);
    }
}
