<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Sandbox;

use RuntimeException;
use Tempest\Support\Filesystem;

final readonly class SandboxPolicy
{
    /** @return list<string> */
    public function command(string $packageRoot, string $stagingRoot, string $entrypoint, ?string $etcPath = null, ?string $sdkRoot = null, bool $network = false): array
    {
        $this->assertRelative($entrypoint);
        $etcMount = $etcPath === null ? ['--dir', '/etc'] : ['--ro-bind', $etcPath, '/etc'];
        $command = [
            'bwrap', '--die-with-parent', '--new-session', '--unshare-user', '--unshare-pid',
            '--unshare-ipc', '--unshare-uts', '--clearenv',
            '--ro-bind', $packageRoot, '/plugin', '--bind', $stagingRoot, '/staging',
            '--tmpfs', '/tmp', '--dev', '/dev', '--dir', '/home', '--dir', '/root',
        ];

        if (! $network) {
            array_splice($command, 5, 0, ['--unshare-net']);
        }

        foreach (['/usr', '/bin', '/lib', '/lib64', '/sbin'] as $directory) {
            if (Filesystem\is_directory($directory)) {
                $command[] = '--ro-bind';
                $command[] = $directory;
                $command[] = $directory;
            }
        }

        if ($sdkRoot !== null) {
            if (! Filesystem\is_directory($sdkRoot)) {
                throw new RuntimeException('plugin SDK root is missing');
            }
            $command = array_merge($command, ['--ro-bind', $sdkRoot, '/sdk']);
        }

        return array_merge($command, $etcMount, [
            '--dir', '/run', '--chdir', '/plugin', '--setenv', 'HOME', '/tmp', '--setenv',
            'PATH', '/usr/local/bin:/usr/bin:/bin', '--', 'php', '/plugin/' . $entrypoint,
        ]);
    }

    private function assertRelative(string $path): void
    {
        $parts = explode('/', $path);

        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            throw new RuntimeException('sandbox entrypoint must be a safe relative path');
        }
    }
}
