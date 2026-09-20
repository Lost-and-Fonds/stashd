# Stashd bubblewrap seccomp profile

`stashd-plugin-bwrap.json` is Docker's built-in seccomp profile from the Moby
commit shipped by the authoritative Ubuntu validation environment:

- Docker Engine `28.0.4`
- Moby tag `v28.0.4`, commit `3bfe60f8c91fca3ea00d87273756372fae7e1f5d`
- upstream source: `profiles/seccomp/default.json`
- upstream SHA-256: `9c1025c88ccaa517b648da571961838744ea2137f176bfe6a48b21294cae9c76`

The only Stashd delta is one non-`CAP_SYS_ADMIN` x86_64 `clone` argument filter.
Docker's v28 rule filters x86_64 `clone` at argument index 0, while bubblewrap's
namespace flags are at index 1. The Stashd rule permits the observed namespace
flag mask (`0x7c020000`) and leaves all other Docker rules unchanged. No mount
syscall is added and no capability is added.

The profile is selected by `docker-compose.yml`; Docker resolves the relative
path from the Compose project directory. It is a Stashd-container policy and
does not require daemon-wide configuration. `docker compose config` should be
used to inspect the resolved `security_opt` entry before deployment.

To check a downloaded Docker/Moby baseline before rebasing this profile:

```sh
deploy/seccomp/check-stashd-plugin-bwrap.sh /path/to/default.json
```

The check is structural and fails if the baseline differs anywhere besides the
one documented x86_64 `clone` rule. Update the pinned provenance and review the
resulting delta whenever Docker's default profile changes.
