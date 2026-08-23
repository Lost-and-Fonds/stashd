<?php

declare(strict_types=1);

namespace Stashd\PluginSdk\Native;

use Closure;
use RuntimeException;
use Stashd\PluginSdk\StagedArtifact;
use Stashd\PluginSdk\StagingArea;

final readonly class NativeStagingArea implements StagingArea
{
    /** @param callable(string,array<string,mixed>):array<string,mixed> $call */
    public function __construct(private Closure $call) {}

    public function write(string $relativePath, string $content, ?string $mediaType = null): StagedArtifact
    {
        $result = ($this->call)('staging.write', [
            'relative_path' => $relativePath,
            'content' => base64_encode($content),
            'media_type' => $mediaType,
        ]);

        return $this->artifact($result, $relativePath);
    }

    public function stage(string $relativePath, ?string $mediaType = null): StagedArtifact
    {
        return $this->artifact(($this->call)('staging.stage', [
            'relative_path' => $relativePath,
            'media_type' => $mediaType,
        ]), $relativePath);
    }

    /** @param array<string,mixed> $result */
    private function artifact(array $result, string $relativePath): StagedArtifact
    {
        if (! is_string($result['reference'] ?? null)) {
            throw new RuntimeException('Native staging returned an invalid artifact.');
        }

        return new StagedArtifact(
            $relativePath,
            is_string($result['media_type'] ?? null) ? $result['media_type'] : 'application/octet-stream',
            is_int($result['size_bytes'] ?? null) ? $result['size_bytes'] : 0,
        );
    }
}
