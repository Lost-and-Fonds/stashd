<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final class PluginBootstrap
{
    public static function load(PluginEntrypoint $entrypoint): PluginRegistry
    {
        $registry = new PluginRegistry();
        $entrypoint->register($registry);

        return $registry;
    }
}
