#!/usr/bin/env python3
"""Machine-checkable M3 schema, codec, and compatibility tests."""

from __future__ import annotations

import argparse
import json
import subprocess
import tempfile
from pathlib import Path
from typing import Any

from codec import SchemaCodec, SchemaError


def interfaces(schema: dict[str, Any]):
    for contract in schema["contracts"]:
        for name, interface in contract["interfaces"].items():
            yield name, interface


def sample(codec: SchemaCodec, interface: str, spec: dict[str, Any], seen: set[tuple[str, str]] | None = None) -> Any:
    seen = seen or set()
    kind = spec["kind"]
    if kind == "scalar":
        return {"bool": True, "string": "value", "u8": 8, "u16": 16, "u32": 32, "u64": 64,
                "s8": -8, "s16": -16, "s32": -32, "s64": -64, "f32": 1.25, "f64": 2.5}[spec["name"]]
    if kind == "list":
        return [sample(codec, interface, spec["value"], seen)]
    if kind == "option":
        return None
    if kind == "result":
        return {"ok": sample(codec, interface, spec["ok"], seen) if spec["ok"] else None}
    if kind == "named":
        key = (interface, spec["name"])
        if key in seen:
            return None
        definition = codec._resolve(interface, spec["name"])
        if definition["kind"] == "record":
            seen.add(key)
            value = {field["name"]: sample(codec, interface, field["type"], seen) for field in definition["fields"]}
            seen.remove(key)
            return value
        if definition["kind"] == "variant":
            branch = definition["values"][0]
            value = {"tag": branch["name"]}
            if branch["type"] is not None:
                value["value"] = sample(codec, interface, branch["type"], seen)
            return value
        if definition["kind"] == "enum":
            return definition["values"][0]
        if definition["kind"] == "resource":
            return {"handle": "resource-1"}
    raise AssertionError(f"unsupported sample type: {spec}")


def each_type(interface_name: str, interface: dict[str, Any]):
    for kind in ("records", "variants", "enums"):
        for name in interface[kind]:
            yield name, {"kind": "named", "name": name}
    for resource in interface["resources"]:
        yield resource["name"], {"kind": "named", "name": resource["name"]}


def all_functions(interface: dict[str, Any]):
    yield from interface["functions"]
    for resource in interface["resources"]:
        yield from resource["functions"]


def canonical_message(codec: SchemaCodec, interface: str, type_name: str, value: Any, message_id: str) -> str:
    definition = {"kind": "named", "name": type_name}
    normalized = codec.validate(interface, definition, value)
    envelope = {"id": message_id, "kind": "request", "type": type_name, "value": normalized}
    return json.dumps(envelope, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def test_schema_shapes(schema: dict[str, Any]) -> None:
    codec = SchemaCodec(schema)
    serialized_schema = json.dumps(schema, sort_keys=True)
    assert "wasmtime" not in serialized_schema.lower()
    assert "component abi" not in serialized_schema.lower()
    type_count = function_count = 0
    for interface_name, interface in interfaces(schema):
        for _, type_spec in each_type(interface_name, interface):
            encoded = codec.encode(interface_name, type_spec, sample(codec, interface_name, type_spec))
            assert codec.decode(interface_name, type_spec, encoded) is not None or type_spec["kind"] == "option"
            type_count += 1
        for function in all_functions(interface):
            for argument in function["arguments"]:
                encoded = codec.encode(interface_name, argument["type"], sample(codec, interface_name, argument["type"]))
                codec.decode(interface_name, argument["type"], encoded)
            if function["result"]:
                spec = function["result"]
                codec.encode(interface_name, spec, sample(codec, interface_name, spec))
                if spec["kind"] == "result":
                    for branch in ("ok", "error"):
                        branch_type = spec[branch]
                        if branch_type is not None:
                            codec.encode(interface_name, {"kind": "result", "ok": spec["ok"], "error": spec["error"]},
                                         {branch: sample(codec, interface_name, branch_type)})
            function_count += 1
    assert type_count > 20
    assert function_count >= 20


def test_options_variants_results(schema: dict[str, Any]) -> None:
    codec = SchemaCodec(schema)
    broadcast = next(interface for name, interface in interfaces(schema) if name == "broadcast-plugin")
    item = {"id": "i", "title": "T", "resources": []}
    item_spec = {"kind": "named", "name": "item"}
    decoded = codec.decode("broadcast-plugin", item_spec, json.dumps({**item, "future-field": "ignored"}))
    assert "future-field" not in decoded and decoded["source-reference"] is None
    try:
        codec.decode("broadcast-plugin", item_spec, json.dumps({"title": "missing id", "resources": []}))
    except SchemaError:
        pass
    else:
        raise AssertionError("missing required field was accepted")

    option_spec = {"kind": "named", "name": "option-value"}
    for tag, value in (("boolean", True), ("number", 7), ("text", "x")):
        assert codec.decode("broadcast-plugin", option_spec, json.dumps({"tag": tag, "value": value}))["tag"] == tag
    error_spec = {"kind": "named", "name": "plugin-error"}
    for branch in ("unsupported", "not-found", "unavailable", "invalid-data", "failed"):
        value = {"tag": branch, "value": {"message": "message", "retryable": False}}
        codec.decode("broadcast-plugin", error_spec, json.dumps(value))
    try:
        codec.decode("broadcast-plugin", error_spec, '{"tag":"future-error","value":{}}')
    except SchemaError:
        pass
    else:
        raise AssertionError("unknown variant was accepted")
    result = {"kind": "result", "ok": {"kind": "named", "name": "operation-result"},
              "error": {"kind": "named", "name": "plugin-error"}}
    codec.encode("broadcast-plugin", result, {"ok": {"choices": [], "values": []}})
    codec.encode("broadcast-plugin", result, {"error": {"tag": "failed", "value": {"message": "x", "retryable": True}}})
    for bad in ('{"ok":{},"error":{}}', '{}'):
        try:
            codec.decode("broadcast-plugin", result, bad)
        except SchemaError:
            pass
        else:
            raise AssertionError("invalid result branch was accepted")
    for bad in ('not-json', '{"tag":"text","value":true}'):
        try:
            codec.decode("broadcast-plugin", option_spec, bad)
        except SchemaError:
            pass
        else:
            raise AssertionError("schema-invalid value was accepted")


def test_every_enum_and_variant_branch(schema: dict[str, Any]) -> None:
    codec = SchemaCodec(schema)
    for interface_name, interface in interfaces(schema):
        for name, definition in interface["enums"].items():
            for value in definition["values"]:
                assert codec.decode(interface_name, {"kind": "named", "name": name}, json.dumps(value)) == value
        for name, definition in interface["variants"].items():
            for branch in definition["values"]:
                value = {"tag": branch["name"]}
                if branch["type"] is not None:
                    value["value"] = sample(codec, interface_name, branch["type"])
                assert codec.decode(interface_name, {"kind": "named", "name": name}, json.dumps(value))["tag"] == branch["name"]


def test_goldens(schema: dict[str, Any], goldens: Path) -> None:
    codec = SchemaCodec(schema)
    publish = {
        "reference": "broadcast:1",
        "settings": [{"key": "format", "value": {"tag": "text", "value": "mp3"}}],
        "sources": [{"reference": "source:1", "settings": []}],
        "items": [{"id": "item:1", "source-reference": "source:1", "title": "Episode", "resources": []}],
    }
    messages = {
        "publish-request.golden": canonical_message(codec, "broadcast-plugin", "publish-request", publish, "golden-1"),
        "plugin-error.golden": canonical_message(codec, "broadcast-plugin", "plugin-error",
                                                   {"tag": "unavailable", "value": {"message": "remote unavailable", "retryable": True}},
                                                   "golden-2"),
    }
    for filename, expected in messages.items():
        actual = (goldens / filename).read_text().strip()
        assert actual == expected, f"non-deterministic or stale golden: {filename}"
    assert messages["publish-request.golden"] == canonical_message(codec, "broadcast-plugin", "publish-request", publish, "golden-1")
    assert messages["plugin-error.golden"] == canonical_message(
        codec,
        "broadcast-plugin",
        "plugin-error",
        {"tag": "unavailable", "value": {"message": "remote unavailable", "retryable": True}},
        "golden-2",
    )


def test_schema_regeneration(root: Path, schema_dir: Path) -> None:
    extractor = root / "spikes/native-plugin-runner/m3/extract_wit.py"
    with tempfile.TemporaryDirectory() as temp:
        subprocess.run(["python3", str(extractor), "--repo-root", str(root), "--output-dir", temp], check=True)
        for name in ("wit-schema.json", "compatibility-report.md"):
            assert (Path(temp) / name).read_bytes() == (schema_dir / name).read_bytes(), f"stale generated {name}"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo-root", type=Path, required=True)
    parser.add_argument("--schema", type=Path, required=True)
    parser.add_argument("--goldens", type=Path, required=True)
    args = parser.parse_args()
    schema = json.loads(args.schema.read_text())
    test_schema_shapes(schema)
    test_options_variants_results(schema)
    test_every_enum_and_variant_branch(schema)
    test_goldens(schema, args.goldens)
    test_schema_regeneration(args.repo_root, args.schema.parent)
    print("M3 schema/codegen bridge: PASS")


if __name__ == "__main__":
    main()
