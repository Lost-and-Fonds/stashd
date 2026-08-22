<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

interface InputPlugin
{
    public function resolve(string $source): ResolvedInput;

    /**
     * @param  list<InputOption>  $options
     * @return list<DiscoveredItem>
     */
    public function discover(string $inputId, DiscoveryIntent $intent, array $options = []): array;

    public function acquire(DiscoveredItem $item, AcquisitionOptions $options): AcquisitionResult;
}
