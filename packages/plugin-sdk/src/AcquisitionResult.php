<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class AcquisitionResult
{
    /** @param list<StagedArtifact> $artifacts */
    public function __construct(public array $artifacts = []) {}
}
