<?php

declare(strict_types=1);

namespace Stashd\PluginSdk\Native;

use Closure;
use Stashd\PluginSdk\ReadableResource;

final class NativeReadableResource implements ReadableResource
{
    private bool $eof = false;

    /** @param callable(string,array<string,mixed>):array<string,mixed> $call */
    public function __construct(private Closure $call, private string $reference) {}

    public function read(int $maximumBytes = 65536): string
    {
        if ($this->eof) {
            return '';
        }
        $result = ($this->call)('resource.read', ['reference' => $this->reference, 'maximum_bytes' => $maximumBytes]);
        $data = base64_decode((string) ($result['data'] ?? ''), true);
        $this->eof = (bool) ($result['eof'] ?? false);

        return $data === false ? '' : $data;
    }

    public function isEof(): bool
    {
        return $this->eof;
    }

    public function close(): void
    {
        $this->eof = true;
    }
}
