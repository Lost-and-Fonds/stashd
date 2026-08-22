<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class Preparation
{
    /** @param list<DerivedArtifact> $artifacts */
    public function __construct(public array $artifacts = []) {}
}
