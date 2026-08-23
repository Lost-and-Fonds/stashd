<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Capabilities;

use RuntimeException;
use Stashd\PluginSdk\ReadableResource;

final class FileResource implements ReadableResource
{
    /** @var resource|null */
    private $handle;

    private bool $closed = false;

    /** @param callable():void $onClose */
    public function __construct(private string $path, private $onClose)
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('resource could not be opened');
        }
        $this->handle = $handle;
    }

    public function read(int $maximumBytes = 65536): string
    {
        if ($this->closed) {
            throw new InvocationClosed('resource is closed');
        }

        if (! is_resource($this->handle)) {
            throw new InvocationClosed('resource is closed');
        }

        return (string) fread($this->handle, max(1, $maximumBytes));
    }

    public function isEof(): bool
    {
        return $this->closed || ! is_resource($this->handle) || feof($this->handle);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->closed = true;
        ($this->onClose)();
    }
}
