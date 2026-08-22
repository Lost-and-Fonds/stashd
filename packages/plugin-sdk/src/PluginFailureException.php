<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

use RuntimeException;

final class PluginFailureException extends RuntimeException
{
    public function __construct(public readonly PluginFailure $failure)
    {
        parent::__construct($failure->error->message);
    }
}
