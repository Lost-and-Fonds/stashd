# Generated M3 WIT compatibility report

This file is generated from the active WIT files; it is not a second contract.

| File | Interface | Records | Variants | Enums | Resources | Functions |
|---|---|---:|---:|---:|---:|---:|
| `plugin-api/wit/input.wit` | `input-host` | 5 | 3 | 1 | 2 | 4 |
| `plugin-api/wit/input.wit` | `input-plugin` | 6 | 2 | 2 | 0 | 3 |
| `plugin-api/wit/broadcast.wit` | `broadcast-host` | 6 | 3 | 1 | 2 | 4 |
| `plugin-api/wit/broadcast.wit` | `broadcast-plugin` | 14 | 2 | 0 | 0 | 4 |

## Native mapping

- scalar values map to JSON scalars;
- records map to JSON objects with required fields and nullable option fields;
- lists map to JSON arrays;
- enums map to strings;
- variants map to `{"tag": string, "value": value}` when a payload exists;
- results map to exactly one of `{"ok": value}` or `{"error": value}`;
- Component resources remain opaque invocation-scoped references; no ABI object is generated.

The generated schema has no Wasmtime or Component ABI dependency. Large-body/resource lifetime details remain pre-freeze follow-ups.
