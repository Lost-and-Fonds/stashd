<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

interface ProgressReporter
{
    public function report(string $stage): void;
}
