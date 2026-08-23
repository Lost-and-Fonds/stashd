# Storage and Vault

Stashd separates **canonical Vault archives** from **Broadcast presentation assets**.

The download → temp → Vault pipeline is provider-neutral. **Fake downloader** is used for tests, dev, and Docker smoke. Bundled external plugins may use helper software such as `yt-dlp`; core only validates and preserves their staged artifacts.

## Layout

```text
/media/vault/{providerKey}/items/{providerItemId}/original.fake   # fake provider
/media/vault/{providerKey}/items/{providerItemId}/metadata.json
/media/vault/{providerKey}/items/{providerItemId}/source.json
/media/vault/{providerKey}/items/{providerItemId}/source-thumbnail.jpg  # when available

/media/temp/downloads/{jobId}/   # staging only — never write partial files into Vault
```

Path segments are sanitized via `PathSanitizer`; Vault paths are built by `VaultPathBuilder`, which refuses paths that escape the Vault root. Human-readable titles are never used as path identity — only sanitized `providerKey` and `providerItemId`.

## Pipeline

```text
POST item.download (async command)
  → DownloadJobHandler
  → DownloadExecutor
       1. DownloadPolicyEvaluator (metadata_only / manual warnings)
       2. Storage root checks (vault + temp writable — DB state and filesystem)
       3. Idempotency: skip when ready Vault original exists on disk (force=false)
       4. TempStagingService creates /media/temp/downloads/{jobId} (cleans stale dirs)
       5. Downloader/plugin writes to temp only (`FakeDownloader` or an external plugin adapter)
       6. Verify all temp outputs exist before any Vault write
       7. SHA-256 checksum (`sha256:{hex}`) + AtomicFileMover into Vault (no overwrite)
       8. Mark all assets ready only after full batch ingest; rollback partial Vault files on failure
       9. Media item → ready via StateTransitionService
      10. Temp cleanup on success; `.failed` marker on error
```

## Downloader boundary

| Type | Role |
|---|---|
| `DownloaderInterface` | Service boundary for all downloads |
| `FakeDownloader` | Phase 4A implementation (tests/dev/CI/smoke) |
| External plugin adapter | Provider-specific acquisition and staged artifacts |
| `DelegatingDownloader` | Routes fake provider → fake; other logical providers → registered plugin |

Core must not call provider helpers directly. A plugin may receive a generic helper grant and is responsible for its own helper invocation and interpretation. The core process only validates staged output before Vault ingest.

## Vault sidecars

| File | Contents |
|---|---|
| `metadata.json` | Normalized Stashd metadata (`schema_version`, provider identity, title, timestamps as RFC3339 `Z`) |
| `source.json` | Provenance + downloader implementation/version + fake/raw result summary |

Secrets are redacted in sidecar JSON (same rules as `SecretsService`).

## Idempotency and retry

| Rule | Behavior |
|---|---|
| Ready original on disk, `force=false` | Skip download; do not overwrite Vault files |
| `force=true` | Returns stable `download_force_not_supported` (not implemented in 4A) |
| Retry after failed temp | `createWorkDirectory` removes stale partial files for the job id |
| Retry after successful Vault move | Skip when original asset + file already satisfied |
| Partial temp / partial ingest | No asset reaches `ready`; partial Vault files rolled back |

Vault originals are **stable and non-destructive** once ready.

## Drift detection

Commands:

- `system.verify_vault` — scan ready Vault assets
- `asset.verify` — verify a single asset

Rules:

- DB is expected state; filesystem is verified reality
- Missing Vault file → asset `missing`, `missing_reason=vault_file_missing`
- Vault original missing → media item `missing`
- Sidecar missing (metadata/source) → asset `missing`; media item stays `ready`
- Checksum mismatch → asset `stale`, `missing_reason=checksum_mismatch` (distinct from missing)
- Unavailable Vault **storage root** → verification skipped (`storage_unavailable=true`, `checked=0`); does **not** mark every asset missing

```text
root unavailable ≠ every file missing
```

## Policies

Stash `downloadPolicy` is enforced at download time:

| Policy | Phase 4A behavior |
|---|---|
| `video` | Fake original media downloaded |
| `audio_only` | Fake audio original (`.fake-audio`) |
| `metadata_only` | Rejects `item.download` with stable error |
| `manual_download` | No automatic scheduling; explicit download allowed with warning |

## API

- `GET /api/v1/items/{id}`
- `GET /api/v1/items/{id}/assets`
- `POST /api/v1/commands` with `type=item.download`

API JSON uses snake_case; PostgreSQL columns retain the application's camelCase
record names.

## Checksums

Vault originals store SHA-256 as `sha256:{hex}`. `asset.verify` / `system.verify_vault` compare stored checksum when present; mismatch is reported separately from a missing file.

## Broadcast hardlink policy (Phase 5A)

Broadcast presentation files live under `/media/broadcasts/` and are **regeneratable**. Canonical media stays in the Vault.

| Rule | Behavior |
|---|---|
| Default publish | Hardlink from Vault original → broadcast path |
| Hardlink probe | Cross-root vault→broadcasts tested at storage check / health |
| Hardlink failure | Stable `broadcast_hardlink_unavailable` — **no silent copy** |
| Symlink/copy | Not implemented in Phase 5A; require explicit future policy |
| Prune | Removes stale generated broadcast files only; never Vault files |
| Broadcast paths | `PathSanitizer::sanitizeBroadcastSegment()` preserves spaces in season/episode names for Jellyfin/Plex layouts |

See `docs/broadcasts/README.md` for lifecycle commands and API.
