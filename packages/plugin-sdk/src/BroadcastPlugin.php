<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

interface BroadcastPlugin
{
    public function prepare(PublishRequest $request): Preparation;

    public function publish(PublishRequest $request): Publication;

    public function finalize(FinalizationRequest $request): Publication;

    public function operation(OperationRequest $request): OperationResult;
}
