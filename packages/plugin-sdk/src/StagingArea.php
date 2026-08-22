<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

interface StagingArea
{
    public function write(string $relativePath, string $content, ?string $mediaType = null): StagedArtifact;

    public function stage(string $relativePath, ?string $mediaType = null): StagedArtifact;
}
