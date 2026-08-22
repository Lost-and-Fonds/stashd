<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

interface PluginEntrypoint
{
    public function register(PluginRegistry $registry): void;
}
