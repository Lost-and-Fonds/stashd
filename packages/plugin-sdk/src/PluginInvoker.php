<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final class PluginInvoker
{
    public static function publish(callable $handler, PublishRequest $request): Publication
    {
        $result = $handler($request);
        if (! $result instanceof Publication) {
            throw new InvalidPluginResultException('publish returned an invalid result');
        }

        return $result;
    }
}
