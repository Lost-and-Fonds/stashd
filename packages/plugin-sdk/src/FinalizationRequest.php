<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class FinalizationRequest
{
    public function __construct(public PublishRequest $request, public Publication $publication) {}
}
