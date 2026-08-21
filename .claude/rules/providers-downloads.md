---
paths:
  - "app/Providers/**/*.php"
  - "app/Downloads/**/*.php"
  - "app/Vault/**/*.php"
  - "tests/**/*Provider*.php"
  - "tests/**/*Download*.php"
  - "docs/providers/**/*.md"
---

# Providers and downloads

Provider code discovers and describes upstream media. Download code retrieves assets through the controlled boundary.

## Provider strategy

- Keep discovery, metadata fetch, and download strategy separate.
- Prefer explicit provider URI/input value objects over raw strings leaking across layers.
- Preserve raw provider metadata snapshots, but redact secrets.
- Use fixtures/fake providers for normal tests.
- Live provider tests are opt-in only.

## External acquisition plugins

- External plugins are the download/process boundary for provider-specific acquisition.
- A plugin may receive a generic helper grant such as `yt-dlp`; core must not shell out
  to provider helpers or interpret their arguments directly.
- Minimize bot/rate-limit risk.
- Prefer fast discovery paths where already designed.
- Keep provider-specific quirks out of Vault/Broadcast code.

## Vault handoff

- Stage downloads into temp first.
- Verify/move into Vault after success.
- Do not mark canonical assets ready before filesystem reality agrees.
- Failed provider/download states must be recoverable and understandable.
