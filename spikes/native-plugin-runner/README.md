# M1 native runner and sandbox skeleton

This is an experimental runner for M1 of the native-plugin roadmap. It is not
production Stashd code and has no RPC, HTTP, credential, SDK, installer, WIT,
or provider integration.

Run the complete check with:

```sh
./test.sh
```

## Runner shape

```text
runner.php
  --staging-root <job staging root>
  --timeout <seconds>
  <absolute package root>
  <relative entrypoint>
        |
        v
    bwrap
        |
        v
  php /plugin/<entrypoint>
```

The package root and entrypoint are validated before launch. The runner creates
one randomly named job directory below the staging root, mounts the package
read-only at `/plugin`, mounts that job directory read/write at `/staging`,
and removes the job directory after the child exits or times out. M1 uses PHP
as the only runtime and does not parse a manifest.

## Bubblewrap policy

The exact policy is deliberately explicit:

```text
--die-with-parent --new-session
--unshare-user --unshare-pid --unshare-ipc --unshare-uts --unshare-net
--clearenv
--ro-bind <package> /plugin
--bind <job staging> /staging
--tmpfs /tmp
--dev /dev
--ro-bind /usr /usr
--ro-bind /bin /bin
--ro-bind /lib /lib
--ro-bind /lib64 /lib64
--ro-bind /sbin /sbin
--ro-bind <minimal etc> /etc
--dir /home --dir /root --dir /run
--chdir /plugin
```

There is no `/proc` mount. This preserves the proven rootless Podman result;
M1 does not weaken the outer container to add one. No Vault, database,
application/data directory, host home, runtime socket, or direct network is
mounted or enabled. The test container runs non-root with all capabilities
dropped and `no-new-privileges`; it does not use privileged mode, `CAP_SYS_ADMIN`,
or seccomp relaxation.

The M1 image installs Debian Bookworm's package
`bubblewrap 0.8.0-2+deb12u1`, which reports `bwrap 0.8.0`. This is the package
provided by the unchanged `php:8.5-cli-bookworm` base; M1 does not upgrade it.

The runner uses `proc_open` with an argument array, not a shell. The child gets
only the environment values explicitly supplied by bubblewrap (`HOME`, `PATH`)
after `--clearenv`.

## Supervision and cleanup

The runner polls the child without blocking, captures stdout/stderr, and
enforces a wall-clock deadline. On timeout it sends TERM, gives the process a
short grace period, then sends KILL. `--die-with-parent` and `--new-session`
ensure a runner death does not leave the sandbox process running. A finalizer
removes only the generated job directory and never follows symlinks outside it.

## M1 boundary

The fixture plugin writes a report into staging so the outer runner can verify
the mount policy. That report is a test artifact, not an RPC format. M2 will
replace this with the language-neutral framed transport.
