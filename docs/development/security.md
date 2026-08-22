# Security practices

Secrets are encrypted at rest and remain behind the secret service. Never log
raw credentials, ciphertext, tokens, password hashes, or secret-bearing job
payloads. Public token failures must not reveal whether a record exists.

Plugins receive only explicitly granted, invocation-scoped capabilities. They
must not receive the Vault root, database access, arbitrary application paths,
raw stored credentials, or unrestricted network access. Core remains
authoritative for Vault promotion, provenance, fixity, and filesystem
publication.

When changing authentication, tokens, credentials, HTTP grants, or sandboxing,
add negative tests for leakage and unauthorized access, then run the relevant
application and container checks.
