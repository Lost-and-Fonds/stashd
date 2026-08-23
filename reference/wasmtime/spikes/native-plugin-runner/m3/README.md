# M3 — WIT schema/codegen bridge

This is a transport-only bridge from the active `input.wit` and
`broadcast.wit` contracts to deterministic JSON data shapes. It is not a PHP
SDK and it does not use Wasmtime, WASI, or Component ABI objects.

`extract_wit.py` is intentionally a small stdlib-only extractor for the
currently active WIT subset. It emits `generated/wit-schema.json` and a
compatibility report. `codec.py` is a handwritten validation harness, not
plugin-facing ergonomics: it proves that the extracted shapes can be encoded
and decoded by a language-neutral RPC implementation.

## Mapping

- WIT scalars become JSON scalars with integer ranges checked.
- Records become objects. Required fields are required; option fields may be
  omitted and normalize to `null`. Unknown object fields are ignored so newer
  senders can add optional data.
- Lists become arrays.
- Enums become strings.
- Variants become `{ "tag": "...", "value": ... }`; unit variants omit
  `value`.
- Results become exactly one of `{ "ok": value }` or `{ "error": value }`.
- Component resources are represented only by an opaque invocation-scoped
  `{ "handle": "..." }` shape. Resource lifetime and large-body transport are
  deliberately deferred to the later resource design milestone.

The generated schema is data only. No generated or validated value refers to
a Wasmtime runtime type, a WIT Component handle, or a host implementation.

Run the complete M3 check from the repository root:

```sh
./spikes/native-plugin-runner/m3/run.sh
```

The check regenerates the committed artifacts, exercises every extracted
record/variant/enum/resource and function shape, covers both branches of
results and all variant branches, checks optional/required/unknown-field
behavior, and compares deterministic golden messages.
