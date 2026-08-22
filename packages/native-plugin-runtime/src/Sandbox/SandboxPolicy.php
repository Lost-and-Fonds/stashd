<?php

declare(strict_types=1);

namespace Stashd\NativeRuntime\Sandbox;

use RuntimeException;

final readonly class SandboxPolicy
{
    /** @return list<string> */
    public function command(string $packageRoot, string $stagingRoot, string $entrypoint, ?string $etcPath = null): array
    {
        $this->assertRelative($entrypoint);
        $etcMount = $etcPath === null ? ['--dir', '/etc'] : ['--ro-bind', $etcPath, '/etc'];
        $command = [
            'bwrap', '--die-with-parent', '--new-session', '--unshare-user', '--unshare-pid',
            '--unshare-ipc', '--unshare-uts', '--unshare-net', '--clearenv',
            '--ro-bind', $packageRoot, '/plugin', '--bind', $stagingRoot, '/staging',
            '--tmpfs', '/tmp', '--dev', '/dev', '--ro-bind', '/usr', '/usr',
            '--ro-bind', '/bin', '/bin', '--ro-bind', '/lib', '/lib', '--ro-bind', '/lib64', '/lib64',
            '--ro-bind', '/sbin', '/sbin', '--dir', '/home', '--dir', '/root',
        ];
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
