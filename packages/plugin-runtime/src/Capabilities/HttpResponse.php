<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Capabilities;

use RuntimeException;

final readonly class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(public int $status, public array $headers = [], public ?string $inlineBody = null, public ?ReadableResource $resource = null) {}

    public function body(): string
    {
        if ($this->inlineBody !== null) {
            return $this->inlineBody;
        }

        throw new RuntimeException('response body is an opaque resource');
    }
}
