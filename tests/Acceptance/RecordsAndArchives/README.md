# Records & Archives acceptance tests

These are executable Pest specifications for Stashd Core vNext, written outside-in
from the agreed preservation architecture. They must ultimately use actual
Tempest application services, real OCFL byte storage, and independently observed
durable records. They are not tests of mocked repositories.

## State of the suite

The fresh dev branch has no Vault implementation yet, so these tests are presently
**INCOMPLETE**, not green. This is intentional. The test fixture refuses to
manufacture a successful result. The adapter is the first integration seam to
implement as real application capabilities arrive.

The one *test-only* interface is
Tests\Support\RecordsAndArchives\ArchivesDriver. Point to an autoloadable class
implementing it with the STASHD_ARCHIVES_ACCEPTANCE_DRIVER environment variable:

    STASHD_ARCHIVES_ACCEPTANCE_DRIVER='Tests\YourRealArchivesDriver' ./vendor/bin/pest --testsuite=Acceptance

Tests with no configured driver are marked incomplete by PHPUnit. A real driver
must exercise the application and preserve genuine results or translate an
expected domain rejection to ArchivesRejected; never return made-up outcomes.

The tests' operation names and result arrays are **NOT** an app-level API design.
The adapter is free to use real Tempest services, HTTP, CLI or supervised
processes. No production class hierarchy is dictated by the test code.

## Isolation

ArchiveFixture creates one private temporary workspace per scenario, including
canonical storage, replica roots, and disposable acquisition input files. It can
physically destroy the old primary in a clean-room restore test. The driver must
also ensure an independent database/schema and isolate jobs, caches, application
config and worker state. In recovery cases marked empty_database or
old_primary_unavailable it must **really** enforce that requirement: flags are
not a substitute for destroying or disconnecting the relevant resources.

No real credentials or configured home-lab storage are ever appropriate test
fixtures. Do not point the driver at a user's working Vault.

## Test-only operation dictionary

Every driver call receives workspace and primary plus explicit parameters.

- vault.initialize and vault.open: return vault_id, an immutable string identity.
- catalogue.list: return items (each entry includes object_id and uri) and assets.
- catalogue.drop_projection: destroy only the disposable test catalogue DB/index.
- asset.preserve: item_uri, asset_key, files (logical path to input filepath),
  optional scope (state open/closed, expected components), optional provenance.
  Return object_id (actual OCFL inventory id) and version (actual OCFL key).
  A successful response is verified canonical preservation, never just enqueue.
- asset.read: object_id, version, logical_path; return bytes from the committed
  stored version, not the input staging path.
- asset.describe: object_id, version; return complete (bool) and
  known_missing (list). Scope is evidence, not optimistic guessing.
- item.record: uri, pins (list of object_id + version), optional metadata;
  return the Item OCFL object_id and its committed version.
- item.read: object_id, optional version; return uri, exact historical pins,
  and metadata for that version.
- metadata.record: item_uri, facet_key, source, JSON document represented as an
  associative array; return independent metadata stream object_id and version.
- metadata.read: object_id, version; return its exact historical document.
- history.events: object_id; return durable events, including relevant
  provenance and repairs. These are archive facts, not ephemeral logs.
- fixity.verify: optional object_id and version; return healthy and issues,
  reflecting actual inventory + byte verification.
- fixity.repair: object_id, version, replica; return repaired and issues.
- replica.sync: replica path; return complete and recovery_point.
- replica.verify: replica path; return healthy and recovery_point.
- replica.recover: replica and destination paths, old_primary_unavailable and
  empty_database constraints; return complete, vault_id, recovery_point, issues.
- fault.interrupt: operation, arguments, phase; run a controlled crash at an
  equivalent real durability boundary; return reported_success. The phase names
  are test hooks, not mandated names for internal transaction states.
- concurrency.preserve: item_uri, asset_key and two competing file sets; run
  truly concurrent writers and return successful version receipts in versions.
- storage.probe: backend and capabilities list; return supports as a map of
  actual capability observations. The restricted-local backend is a test adapter
  with hardlinks disabled (not a required production storage driver).

Failures intentionally rejected by the app must throw ArchivesRejected. Other
errors and failed test setup must not be swallowed. The driver must use the OCFL
object id in the receipt, so the tests can scan the actual object inventory.
Results are semantic observable outcomes; internal method names are unrestricted.

## Independent proofs and limits

OcflInspector separately reads root marker 0=ocfl_1.1, root inventory and digest
sidecar, and version-specific physical payload bytes. It is **not a full OCFL
conformance validator**. Full conformance requires official external OCFL tools,
real cloud-storage adapters and crash/failure testing under the intended
operating environment. A green local fixture alone cannot prove power-loss
durability, distributed locking, remote compatibility or memory bounds.

The test suite covers Vault identity and safe initialization, OCFL Assets and
independent version histories, canonical Items and exact pins, metadata and
source history, truthful coverage evidence, fixity and repair, crash windows,
writer collisions, replicas, clean-room reconstruction, and capability honesty.

The longer-term Application State snapshot, protected secrets and the recovery
wizard belong primarily to Administration and Security contracts, not to this
department's media-preservation tests. Plugin RPC checkpoint acknowledgements
belong to Acquisition/Containment acceptance, with explicit non-guarantee of
total-host-loss survival before a coherent replica recovery point.

## TDD agreement

Write the whole department contract before implementation, but progress through
one test group at a time. Start with VaultIdentityTest. Continue only after
meaningful assertions pass against real persisted OCFL evidence. A test that
is incomplete because the adapter is absent is not red and not proof.

Codex and other implementers may write unit and integration tests freely. They
must not silently change acceptance expectations to match an implementation.
Review apparent contradictions together first.
