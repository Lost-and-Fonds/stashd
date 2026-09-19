# Docker AppArmor prerequisite

Ubuntu hosts with `kernel.apparmor_restrict_unprivileged_userns=1` need the
checked-in `stashd-plugin-bwrap` profile before the plugin sandbox can run.
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
child profile removes capabilities, nested user namespaces, mounts, and
network access. Stashd's bubblewrap mounts remain the authority for package,
staging, application, data, and secret visibility.

Hosts without AppArmor continue to use `docker-compose.yml`; the AppArmor
override is an explicit host-policy selection and does not install policy.

Compose still selects `seccomp=unconfined` because the current bubblewrap
probe has not yet been reduced to a safe repository-shipped seccomp delta. A
minimal seccomp profile is a separate follow-up; this prerequisite does not
weaken AppArmor or grant container capabilities.
