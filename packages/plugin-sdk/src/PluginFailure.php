<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class PluginFailure
{
    public function __construct(public PluginErrorCode $code, public PluginError $error) {}
}
