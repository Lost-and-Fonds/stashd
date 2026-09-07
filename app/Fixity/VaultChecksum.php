<?php

declare(strict_types=1);

namespace App\Fixity;

use Closure;
use RuntimeException;

/** SHA-256 checksum helper for Vault assets. Stored format: `sha256:{hex}`. */
final class VaultChecksum
{
    private const int CHUNK_BYTES = 1048576;

    public const string ALGORITHM = 'sha256';

    public static function computeFile(string $path, ?Closure $onChunk = null): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $hash = hash_init(self::ALGORITHM);

        try {
            while (! feof($handle)) {
                $chunk = @fread($handle, self::CHUNK_BYTES);

                if ($chunk === false) {
                    return null;
                }

                if ($chunk === '') {
                    continue;
                }

                hash_update($hash, $chunk);
                $onChunk?->__invoke();
            }
        } finally {
            fclose($handle);
        }

        return self::format(hash_final($hash));
    }

    public static function requiredFile(string $path, ?Closure $onChunk = null): string
    {
        return self::computeFile($path, $onChunk)
            ?? throw new RuntimeException("Unable to compute SHA-256 checksum for: {$path}");
    }

    public static function format(string $hexDigest): string
    {
        return self::ALGORITHM . ':' . strtolower($hexDigest);
    }

    public static function verifyFile(string $path, ?string $storedChecksum, ?Closure $onChunk = null): ChecksumComparison
    {
        $computed = self::computeFile($path, $onChunk);

        if ($computed === null) {
            return new ChecksumComparison(
                outcome: ChecksumComparisonOutcome::Unavailable,
                expectedChecksum: $storedChecksum,
                observedChecksum: null,
            );
        }

        if ($storedChecksum === null || $storedChecksum === '') {
            return new ChecksumComparison(
                outcome: ChecksumComparisonOutcome::Unverified,
                expectedChecksum: null,
                observedChecksum: $computed,
            );
        }

        return new ChecksumComparison(
            outcome: hash_equals(strtolower($storedChecksum), strtolower($computed))
                ? ChecksumComparisonOutcome::Match
                : ChecksumComparisonOutcome::Mismatch,
            expectedChecksum: $storedChecksum,
            observedChecksum: $computed,
        );
    }
}
