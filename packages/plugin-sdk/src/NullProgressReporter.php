<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final class NullProgressReporter implements ProgressReporter
{
    public function report(string $stage): void {}
}
