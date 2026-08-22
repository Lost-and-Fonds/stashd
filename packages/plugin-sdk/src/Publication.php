<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class Publication
{
    /**
     * @param  list<PublishedFile>  $files
     * @param  list<Setting>  $publishedMetadata
     */
    public function __construct(public Artifact $artifact, public array $files = [], public array $publishedMetadata = []) {}
}
