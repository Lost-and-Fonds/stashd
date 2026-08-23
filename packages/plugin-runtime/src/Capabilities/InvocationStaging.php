<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Capabilities;

use Stashd\PluginSdk\StagedArtifact;
use Stashd\PluginSdk\StagingArea;

use function Tempest\Support\Filesystem\create_directory;
use function Tempest\Support\Filesystem\exists;
use function Tempest\Support\Filesystem\is_directory;
use function Tempest\Support\Filesystem\is_file;

final class InvocationStaging implements StagingArea
{
    public function __construct(private string $root) {}

    public function write(string $relativePath, string $content, ?string $mediaType = null): StagedArtifact
    {
        $path = $this->safePath($relativePath, false);
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new UnsafePath('staging output already exists or cannot be created');
        }

        try {
            fwrite($handle, $content);
        } finally {
            fclose($handle);
        }

        return $this->stage($relativePath, $mediaType);
    }

    public function stage(string $relativePath, ?string $mediaType = null): StagedArtifact
    {
        $path = $this->safePath($relativePath, true);

        return new StagedArtifact('staging:' . hash('sha256', $relativePath), $mediaType ?? 'application/octet-stream', (int) filesize($path));
    }

    public function output(string $relativePath, ?string $mediaType = null): PublishedOutput
    {
        $path = $this->safePath($relativePath, true);

        return new PublishedOutput('staging:' . hash('sha256', $relativePath), $relativePath, (int) filesize($path), $mediaType);
    }

    private function safePath(string $relativePath, bool $mustExist): string
    {
        if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, "\0")) {
            throw new UnsafePath('staging path must be relative');
        }
        $parts = explode('/', $relativePath);

        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            throw new UnsafePath('staging path contains an unsafe segment');
        }
        $cursor = $this->root;
        $last = array_pop($parts);

        foreach ($parts as $part) {
            $cursor .= '/' . $part;

            if (is_link($cursor)) {
                throw new UnsafePath('staging path crosses a symlink');
            }

            if (exists($cursor) && ! is_directory($cursor)) {
                throw new UnsafePath('staging path crosses a file');
            }

            if (! is_directory($cursor)) {
                try {
                    create_directory($cursor, 0700);
                } catch (\Throwable $exception) {
                    throw new UnsafePath('staging directory could not be created', 0, $exception);
                }
            }
        }
        $path = $cursor . '/' . $last;

        if (is_link($path)) {
            throw new UnsafePath('staging target is a symlink');
        }

        if ($mustExist && ! is_file($path)) {
            throw new UnsafePath('staging output does not exist');
        }

        return $path;
    }
}
