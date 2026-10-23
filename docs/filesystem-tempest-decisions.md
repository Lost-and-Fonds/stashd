# PR2 Tempest alignment

Scope: the Filesystem configuration/discovery layer in #68/#69, using installed
Tempest **3.19.2**. This is a review of the existing increment, not a replacement
for the preservation engine or a new plugin runtime.

Read local docs `1-essentials/03-database.md`, `05-container.md`,
`06-configuration.md`, `2-features/01-mapper.md`, `03-validation.md`,
`05-file-storage.md` and `16-process.md`, and the installed implementations below.
Paths are relative to `vendor/tempest/framework/packages/` unless stated otherwise.

## Changes and PR1 retrospective

PR1's Tempest console commands, discovered configuration object and domain path
policy remain appropriate. Its direct PDO connection, inline table bootstrap and
routine SQL were avoidable framework substitutes. PR2 now adapts the existing
explicit DSN to `DatabaseConfig`, opens Tempest's nonpersistent application
connection lazily, uses the table query builder for ordinary configuration/run
CRUD, and applies generated `MigratesUp` migrations through `MigrationManager`.
The private schema is the connection's sole search path; projection schemas and
canonical Vault storage remain separate. `integration:init` remains the explicit
bootstrap/upgrade command. Existing IDs/settings/constraints survive adoption of
PR1's table without a migration ledger; repeated upgrades retain the same ledger.

Tempest `#[Autowire]` replaces the single-line Input initializer.
`DiscoveryCoordinator` now owns the Input lifecycle, not persistence. The small
`DiscoveryStore` owns checkpoint SQL. Named request, observation, batch, result,
metadata, diagnostic, deficiency, cursor and frame records replace anonymous
structural checks. Tempest `#[Strict]` mapping enforces required nullable fields;
class/property rules validate raw values **before** mapping can coerce them.
Collection elements are checked explicitly because class validation does not
recurse through typed array elements. The shared Filesystem policy validates
relative names both in enumeration and when restoring frames.

## Narrow exceptions and revisit conditions

- **Strict protocol values.** Evaluated `validation/src/Validator.php`,
  `Rules/IsInteger.php`, `IsBoolean.php`, `IsString.php`, `IsArray.php`,
  `IsArrayList.php` and `mapper/src/Mappers/ArrayToObjectMapper.php`/casters.
  Built-in scalar rules intentionally accept coercible values; class validation
  presents nested arrays as Tempest array views, which `IsArray` rejects.
  `IsProtocolValue` is a reusable Tempest attribute rule that preserves strict
  scalar types and accepts those internal array views. Built-in list/count/enum
  rules still apply. Strict mapping supplies presence checks; no custom required
  field walker remains. A small Tempest `IsDiscoveryRecord` rule rejects dotted
  field aliases before the mapper can expand them into validated protocol fields;
  those aliases are useful for forms but are not protocol record keys. Revisit if installed rules gain strict modes and
  consistent nested-array handling and literal-key mapping. Rejection tests cover missing versus null,
  numeric/boolean lookalikes, malformed variants and cursor frames.
- **Explicit transaction errors.** Evaluated `database/src/GenericDatabase.php`
  `withinTransaction()` and `Transactions/GenericTransactionManager.php`.
  `withinTransaction()` catches every Throwable and returns only a boolean,
  losing the failure needed for resumable diagnostics. The operational adapter
  uses Tempest's transaction manager on the same connection, returning the
  callback value and rethrowing the original error after rollback. Revisit if
  the framework offers a result-preserving, rethrowing transaction API. Real SQL
  fault injection proves observations, associations and coverage roll back together.
- **PostgreSQL operations.** Evaluated table query builders, `Query`,
  `CreateTableStatement`, `MigratesUp`, `MigrationManager` and plain models/
  `IsDatabaseModel`. Ordinary persistence uses the framework's supported
  table-oriented builder; no competing model layer exists. Parameterized `Query`
  remains for advisory/session locks, exact JSONB configuration equality,
  `FOR UPDATE`, conflict upserts, a checkpoint update with `batches + 1`, and the
  coherent status/count SELECT. Schema creation/search-path setup are PostgreSQL
  operations. Migrations use the schema builder, with raw CHECK/UUID foreign-key/
  composite-key clauses and a partial unique index where its APIs cannot express
  the exact constraints. Initial adoption adds `IF NOT EXISTS` to compiled table
  statements because the builder has no adoption flag. `to_regclass` avoids the
  manager's missing-ledger probe aborting the surrounding PostgreSQL transaction;
  Tempest creates and records its own ledger. Revisit each exception when the
  installed builder/manager can express it without weakening schema isolation,
  serialization or atomic acknowledgment. CLI proofs cover repeatable legacy
  upgrade, configuration authority, live invocation exclusion, SQL failure and
  SIGKILL/resume.
- **Existing explicit DSN.** Evaluated `Config/PostgresConfig.php`,
  `Config/DatabaseConfig.php`, `DatabaseInitializer.php` and `Connection/PDOConnection.php`.
  `PostgresConfig` builds a DSN from host/port/database fields; parsing the existing
  operator DSN would discard or reinterpret connection options. A small
  `DatabaseConfig` adapter retains that DSN and credentials, while Tempest owns
  the connection. Lazy registration keeps Vault-only commands SQL-independent.
  Revisit if Tempest's Postgres config accepts an explicit DSN, or if Core changes
  its connection-setting contract. CLI tests retain explicit-connection rejection
  and independent schema/source/Vault witnesses.
- **Local discovery traversal.** Evaluated `storage/src/Storage.php`,
  `GenericStorage::list()`, `Config/LocalStorageConfig.php` and installed
  `league/flysystem-local/LocalFilesystemAdapter.php`. Listing offers no durable
  entry-position checkpoint, normalizes backslashes, and either skips links
  without a coverage diagnostic or aborts on them. Its normal adapter also creates
  the root at construction. Native `DirectoryIterator`/`lstat` and the existing
  Filesystem policy retain exact supported names, no source creation, resumable
  traversal and explicit skipped-entry coverage. Revisit for a listing API with
  those semantics, or a separately approved snapshot/index design. Recursive,
  exclusion, root-replacement and crash/resume CLI proofs protect these choices.
- **Process evidence in the existing test harness.** Evaluated
  `process/src/ProcessExecutor.php`, `PendingProcess.php`, `InvokedSystemProcess.php`
  and `ProcessResult.php`. The API can signal a child but its result does not expose
  final OS `signaled`/`termsig` evidence; waiting on a signaled process exposes
  Symfony's exception instead. Keep the small existing native-process helper for
  fresh CLI proofs and direct SIGKILL/status assertions. Production discovery
  launches no process. Revisit when the framework exposes that termination evidence
  directly. The crash test asserts signal 9, committed row counts and continuation
  adoption after a fresh invocation.

No scheduling, jobs, HTTP write surface or external process runtime is introduced.
No dependency changes or parallel framework are needed. Existing continuation
JSON and CLI output shapes remain compatible; scans still require a stable tree
for reliable entry-position continuation.
