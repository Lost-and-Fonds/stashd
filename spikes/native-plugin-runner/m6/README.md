# M6 — Package validation and local linking

M6 is a provider-neutral package lifecycle spike. It uses `tar.gz` fixture
archives and a small PHP tar reader that validates every entry before writing
it. Installed packages live beside their immutable version directory and are
activated through an atomic symlink switch:

```text
<root>/packages/<id>/<version>/
<root>/active/<id> -> ../packages/<id>/<version>
<root>/links/<id> -> development source
<root>/staging/
```

Checks cover manifest/runtime/API/PHP/extension/architecture compatibility,
checksums, archive traversal/link safety, side-by-side versions, rollback,
disable/remove, and failed-install preservation. Development links are kept
outside installed packages and execute through the M5 bubblewrap policy with a
read-only source mount.

Run the complete M6 check from the repository root:

```sh
./spikes/native-plugin-runner/m6/run.sh
```

The script runs M3/M4, executes M6 in a fresh non-root container, then runs
the M1–M5 regression chain.
