<?php

declare(strict_types=1);

namespace App\Vault;

use App\Config\StashdConfig;
use App\System\Storage\PathSanitizer;
use InvalidArgumentException;

final readonly class VaultPathBuilder
{
    public function __construct(
        private StashdConfig $config,
    ) {}

    public function itemDirectory(string $providerKey, string $providerItemId): string
    {
        $safeProvider = PathSanitizer::sanitizeSegment($providerKey);
        $safeItem = PathSanitizer::sanitizeSegment($providerItemId);
        $path = rtrim($this->config->vaultPath(), '/') . "/{$safeProvider}/items/{$safeItem}";
        $this->assertWithinVaultRoot($path);

        return $path;
    }

    public function vaultFile(string $providerKey, string $providerItemId, string $filename): string
    {
        $path = $this->itemDirectory($providerKey, $providerItemId) . '/' . PathSanitizer::sanitizeSegment($filename);
        $this->assertWithinVaultRoot($path);

        return $path;
    }

    public function assertWithinVaultRoot(string $path): void
    {
        $vaultRoot = rtrim(str_replace('\\', '/', $this->config->vaultPath()), '/');
        $candidate = str_replace('\\', '/', $path);

        if ($candidate === $vaultRoot || str_starts_with($candidate, $vaultRoot . '/')) {
            return;
        }

        $resolvedVault = realpath($vaultRoot);
        $resolvedParent = realpath(dirname($path));

        if (
            $resolvedVault !== false
            && $resolvedParent !== false
            && str_starts_with($resolvedParent, $resolvedVault)
        ) {
            return;
        }

        throw new InvalidArgumentException('Vault path escapes storage root.');
    }

    public function relativeFile(string $providerKey, string $providerItemId, string $filename): string
    {
        $safeProvider = PathSanitizer::sanitizeSegment($providerKey);
        $safeItem = PathSanitizer::sanitizeSegment($providerItemId);
        $safeFilename = PathSanitizer::sanitizeSegment($filename);

        return "{$safeProvider}/items/{$safeItem}/{$safeFilename}";
    }
}
