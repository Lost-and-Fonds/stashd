<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Package;

use Tempest\DateTime\Duration;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\PendingProcess;
use Tempest\Support\Filesystem;

/** Pulls a registry reference into a standard OCI image layout. */
final readonly class OciRegistry
{
    public const BINARY = '/usr/local/libexec/stashd/oras';

    public function __construct(private ?string $binary = null) {}

    public function pull(string $reference, string $layout, string $platform): string
    {
        if (trim($reference) === '' || preg_match('/\s/', $reference) === 1 || ! preg_match('#^[a-zA-Z0-9][a-zA-Z0-9._:/@-]+$#', $reference)) {
            throw new PackageValidationError('OCI reference is invalid');
        }

        if (! preg_match('#^linux/(amd64|arm64)$#', $platform)) {
            throw new PackageValidationError('OCI plugin platform is unsupported');
        }

        $binary = $this->binary ?? (is_string(getenv('STASHD_ORAS_BINARY')) && trim((string) getenv('STASHD_ORAS_BINARY')) !== '' ? trim((string) getenv('STASHD_ORAS_BINARY')) : self::BINARY);

        if (! Filesystem\is_file($binary)) {
            throw new PackageValidationError('verified ORAS binary is not installed');
        }

        $result = (new GenericProcessExecutor())->run(new PendingProcess(
            [$binary, 'cp', '--to-oci-layout', '--platform', $platform, $reference, $layout . ':stashd'],
            Duration::seconds(300),
        ));

        if (! $result->successful()) {
            throw new PackageValidationError('OCI pull failed: ' . trim($result->errorOutput . ' ' . $result->output));
        }

        return (new Umoci())->manifestDigest($layout, 'stashd');
    }
}
