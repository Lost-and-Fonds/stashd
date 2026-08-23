<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Package;

use RuntimeException;
use Tempest\DateTime\Duration;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\PendingProcess;
use Tempest\Process\ProcessResult;
use Tempest\Support\Filesystem;

/** The pinned core OCI tool. It is never resolved from PATH. */
final class Umoci
{
    public const VERSION = '0.6.0';

    public const BINARY = '/usr/local/libexec/stashd/umoci';

    private string $binary;

    public function __construct(?string $binary = null)
    {
        $this->binary = $binary ?? (is_string(getenv('STASHD_UMOCI_BINARY')) && trim((string) getenv('STASHD_UMOCI_BINARY')) !== '' ? trim((string) getenv('STASHD_UMOCI_BINARY')) : self::BINARY);

        if (! Filesystem\is_file($this->binary)) {
            throw new RuntimeException('verified umoci binary is not installed');
        }
    }

    /**
     * @param list<string> $arguments
     * @param array<string, mixed> $environment
     */
    public function run(array $arguments, string $workingDirectory, int $timeout = 300, array $environment = []): ProcessResult
    {
        $baseEnvironment = getenv();
        $result = (new GenericProcessExecutor())->run(new PendingProcess(
            [$this->binary, ...$arguments],
            Duration::seconds($timeout),
            path: $workingDirectory,
            environment: array_merge($baseEnvironment, $environment),
        ));

        if (! $result->successful()) {
            throw new PackageValidationError('umoci failed: ' . trim($result->errorOutput . ' ' . $result->output));
        }

        return $result;
    }

    public function init(string $layout): void
    {
        $this->run(['init', '--layout', $layout], dirname($layout));
    }

    public function newImage(string $layout, string $tag): void
    {
        $this->run(['new', '--image', $layout . ':' . $tag], dirname($layout));
    }

    public function unpack(string $layout, string $tag, string $bundle): void
    {
        $this->run(['unpack', '--rootless', '--image', $layout . ':' . $tag, $bundle], dirname($layout));
    }

    /** @param array<string, mixed> $environment */
    public function repack(string $layout, string $tag, string $bundle, array $environment = []): void
    {
        $this->run(['repack', '--image', $layout . ':' . $tag, '--compress', 'gzip', $bundle], dirname($layout), environment: $environment);
    }

    /** @param array<string, mixed> $environment */
    public function configure(string $layout, string $tag, string $platform, array $environment = []): void
    {
        $this->run([
            'config', '--image', $layout . ':' . $tag,
            '--platform.os', 'linux',
            '--platform.arch', substr($platform, 6),
            '--config.label', 'io.stashd.plugin.platform=' . $platform,
            '--created', '1970-01-01T00:00:00Z',
        ], dirname($layout), environment: $environment);
    }

    /** @return array<string, mixed> */
    public function stat(string $layout, string $tag): array
    {
        $value = json_decode($this->run(['stat', '--json', '--image', $layout . ':' . $tag], dirname($layout))->output, true);

        if (! is_array($value)) {
            throw new PackageValidationError('umoci returned invalid image metadata');
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new PackageValidationError('umoci returned invalid image metadata');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    public function manifestDigest(string $layout, string $tag): string
    {
        $stat = $this->stat($layout, $tag);
        $manifest = $stat['manifest'] ?? null;
        $descriptor = is_array($manifest) ? ($manifest['descriptor'] ?? null) : null;
        $digest = is_array($descriptor) ? ($descriptor['digest'] ?? null) : null;

        if (! is_string($digest) || ! preg_match('/^sha256:[a-f0-9]{64}$/', $digest)) {
            throw new PackageValidationError('umoci returned an invalid manifest digest');
        }

        return $digest;
    }
}
