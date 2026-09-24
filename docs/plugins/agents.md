# Plugin agent notes

## Ownership

- `plugin-api` owns the language-neutral WIT contract and schemas.
- `stashd-php-sdk` implements the contract for PHP authors.
- Provider repositories own provider protocols and package behavior.
- Core owns generic plugin invocation, capabilities, package lifecycle, Vault
  authority, and application integration.

## Current compatibility baseline

```text
contract    stashd:plugin@0.2.0
manifest    api_version: "0.2"
PHP SDK     stashd/php-sdk:^0.3
runtime     php
RPC         v1
```

The WIT is the normative contract. The PHP SDK is one language binding, not an
ABI definition. Keep provider-specific behavior out of Core and do not give
plugins direct database, Vault, filesystem, credential, or unrestricted network
access. Use the declared invocation-scoped capabilities.

Read [the authoring guide](authoring.md), [manifest reference](manifest.md),
and [PHP SDK reference](php-sdk.md) before changing a plugin boundary. Use the
source repository's lint, static-analysis, and production package-build commands
where applicable.

The Stashd repositories currently contain no automated tests or test harnesses.
A replacement testing system will be designed from first principles in a later
phase.
