#!/usr/bin/env python3
"""Small transport-neutral JSON codec for the generated M3 WIT schema."""

from __future__ import annotations

import json
import math
from typing import Any


class SchemaError(ValueError):
    """Raised when a value does not satisfy the extracted WIT shape."""


class SchemaCodec:
    def __init__(self, schema: dict[str, Any]) -> None:
        self.schema = schema
        self._types: dict[tuple[str, str], dict[str, Any]] = {}
        self._global: dict[str, list[dict[str, Any]]] = {}
        self._uses: dict[str, dict[str, str]] = {}
        for contract in schema["contracts"]:
            for interface_name, interface in contract["interfaces"].items():
                self._uses[interface_name] = interface.get("uses", {})
                for kind in ("records", "variants", "enums"):
                    for name, definition in interface[kind].items():
                        value = {"kind": kind[:-1], **definition}
                        self._types[(interface_name, name)] = value
                        self._global.setdefault(name, []).append(value)
                for resource in interface["resources"]:
                    value = {"kind": "resource", **resource}
                    self._types[(interface_name, resource["name"])] = value
                    self._global.setdefault(resource["name"], []).append(value)

    def encode(self, interface: str, type_spec: dict[str, Any], value: Any) -> str:
        normalized = self.validate(interface, type_spec, value)
        return json.dumps(normalized, sort_keys=True, separators=(",", ":"), ensure_ascii=False)

    def decode(self, interface: str, type_spec: dict[str, Any], payload: str) -> Any:
        try:
            value = json.loads(payload)
        except json.JSONDecodeError as error:
            raise SchemaError(f"$: invalid JSON: {error.msg}") from error
        return self.validate(interface, type_spec, value)

    def validate(self, interface: str, type_spec: dict[str, Any], value: Any, path: str = "$") -> Any:
        kind = type_spec["kind"]
        if kind == "scalar":
            return self._scalar(type_spec["name"], value, path)
        if kind == "list":
            if not isinstance(value, list):
                raise SchemaError(f"{path}: expected list")
            return [self.validate(interface, type_spec["value"], item, f"{path}[{index}]") for index, item in enumerate(value)]
        if kind == "option":
            if value is None:
                return None
            return self.validate(interface, type_spec["value"], value, path)
        if kind == "result":
            if not isinstance(value, dict) or set(value) not in ({"ok"}, {"error"}):
                raise SchemaError(f"{path}: expected exactly one result branch")
            branch = "ok" if "ok" in value else "error"
            branch_type = type_spec[branch]
            if branch_type is None:
                if value[branch] is not None:
                    raise SchemaError(f"{path}.{branch}: expected null")
                return {branch: None}
            return {branch: self.validate(interface, branch_type, value[branch], f"{path}.{branch}")}
        if kind == "named":
            definition = self._resolve(interface, type_spec["name"])
            if definition["kind"] == "record":
                return self._record(interface, definition, value, path)
            if definition["kind"] == "variant":
                return self._variant(interface, definition, value, path)
            if definition["kind"] == "enum":
                if not isinstance(value, str) or value not in definition["values"]:
                    raise SchemaError(f"{path}: unknown enum value")
                return value
            if definition["kind"] == "resource":
                if not isinstance(value, dict) or not isinstance(value.get("handle"), str):
                    raise SchemaError(f"{path}: expected opaque resource handle")
                return {"handle": value["handle"]}
        raise SchemaError(f"{path}: unsupported type {kind}")

    def _resolve(self, interface: str, name: str) -> dict[str, Any]:
        local = self._types.get((interface, name))
        if local:
            return local
        imported_from = self._uses.get(interface, {}).get(name)
        if imported_from:
            imported = self._types.get((imported_from, name))
            if imported:
                return imported
        candidates = self._global.get(name, [])
        if len(candidates) == 1:
            return candidates[0]
        if not candidates:
            raise SchemaError(f"$: unknown named type {name}")
        raise SchemaError(f"$: ambiguous named type {name}")

    def _record(self, interface: str, definition: dict[str, Any], value: Any, path: str) -> dict[str, Any]:
        if not isinstance(value, dict):
            raise SchemaError(f"{path}: expected object")
        normalized: dict[str, Any] = {}
        for field in definition["fields"]:
            name, field_type = field["name"], field["type"]
            if name not in value:
                if field_type["kind"] == "option":
                    normalized[name] = None
                    continue
                raise SchemaError(f"{path}.{name}: missing required field")
            normalized[name] = self.validate(interface, field_type, value[name], f"{path}.{name}")
        return normalized

    def _variant(self, interface: str, definition: dict[str, Any], value: Any, path: str) -> dict[str, Any]:
        if not isinstance(value, dict) or not isinstance(value.get("tag"), str):
            raise SchemaError(f"{path}: expected variant object")
        branch = next((item for item in definition["values"] if item["name"] == value["tag"]), None)
        if branch is None:
            raise SchemaError(f"{path}.tag: unknown variant value")
        if branch["type"] is None:
            if "value" in value:
                raise SchemaError(f"{path}.value: unit variant cannot carry a value")
            return {"tag": value["tag"]}
        if "value" not in value:
            raise SchemaError(f"{path}.value: missing variant payload")
        return {"tag": value["tag"], "value": self.validate(interface, branch["type"], value["value"], f"{path}.value")}

    @staticmethod
    def _scalar(name: str, value: Any, path: str) -> Any:
        if name == "bool":
            valid = isinstance(value, bool)
        elif name == "string":
            valid = isinstance(value, str)
        elif name.startswith(("u", "s")):
            valid = isinstance(value, int) and not isinstance(value, bool)
            if valid:
                bits = int(name[1:])
                valid = 0 <= value < 2**bits if name[0] == "u" else -(2 ** (bits - 1)) <= value < 2 ** (bits - 1)
        elif name.startswith("f"):
            valid = isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(float(value))
        else:
            valid = False
        if not valid:
            raise SchemaError(f"{path}: invalid {name}")
        return value
