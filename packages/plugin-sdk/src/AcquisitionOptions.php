<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class AcquisitionOptions
{
    /** @param list<InputOption> $options */
    public function __construct(public MediaKind $mediaKind, public array $options = []) {}
}
