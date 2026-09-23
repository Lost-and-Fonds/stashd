# Docker AppArmor prerequisite

AppArmor 4.x is currently required for the checked-in `stashd-plugin-bwrap`
profile. Ubuntu 24.04-class hosts with
`kernel.apparmor_restrict_unprivileged_userns=1` need the profile before the
plugin sandbox can run.
Install it as root:

```sh
sudo deploy/apparmor/install-stashd-plugin-bwrap.sh --require
docker compose -f docker-compose.yml -f docker-compose.apparmor.yml up -d
```

The installer is idempotent. It checks that AppArmor is active, installs the
profile, validates and loads it with `apparmor_parser`, and verifies the loaded
profile name. It never changes AppArmor mode or the user-namespace sysctl.

The base profile follows Docker's `docker-default` restrictions, including its
mount denial. Only the `/usr/bin/bwrap` or `/usr/local/bin/bwrap` execution
transition receives user-namespace, mount, umount, and pivot permissions. The
child profile removes capabilities, nested user namespaces, and mounts.
AppArmor permits network operations inside the child; bubblewrap controls
network availability per invocation. Default invocations use `--unshare-net`.
Explicitly network-granted helpers omit it. Stashd's bubblewrap mounts remain
the authority for package, staging, application, data, and secret visibility.

Hosts without AppArmor continue to use `docker-compose.yml`; the AppArmor
override is an explicit host-policy selection and does not install policy.

To remove the optional host policy, stop containers using the override, unload
the profile, and remove its file:

```sh
sudo apparmor_parser -R /etc/apparmor.d/stashd-plugin-bwrap
sudo rm /etc/apparmor.d/stashd-plugin-bwrap
docker compose up -d
```

The normal base Compose file does not install or mutate AppArmor policy.

Compose selects the checked-in `deploy/seccomp/stashd-plugin-bwrap.json`
profile. It allows only the additional bubblewrap namespace and mount
operations documented in `deploy/seccomp/README.md`; AppArmor remains enabled,
and no container capabilities are added.
