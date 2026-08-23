#!/usr/bin/env python3
"""Extract the active M0 WIT subset into deterministic transport metadata."""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path


DECLARATION = re.compile(r"\b(record|variant|enum|resource)\s+([A-Za-z0-9-]+)\s*\{")
INTERFACE = re.compile(r"\b(interface|world)\s+([A-Za-z0-9-]+)\s*\{")
FUNCTION = re.compile(r"([A-Za-z0-9-]+)\s*:\s*func\((.*?)\)\s*(?:->\s*([^;]+))?;", re.S)


def matching_brace(source: str, opening: int) -> int:
    depth = 0
    for index in range(opening, len(source)):
        if source[index] == "{":
            depth += 1
        elif source[index] == "}":
            depth -= 1
            if depth == 0:
                return index
    raise ValueError("unclosed WIT block")


def split_top_level(value: str, delimiter: str = ",") -> list[str]:
    values: list[str] = []
    start = 0
    angle = 0
    paren = 0
    for index, character in enumerate(value):
        if character == "<":
            angle += 1
        elif character == ">":
            angle -= 1
        elif character == "(":
            paren += 1
        elif character == ")":
            paren -= 1
        elif character == delimiter and angle == 0 and paren == 0:
            values.append(value[start:index].strip())
            start = index + 1
    tail = value[start:].strip()
    if tail:
        values.append(tail)
    return values


def parse_type(value: str) -> dict:
    value = " ".join(value.strip().split())
    for wrapper in ("list", "option"):
        prefix = f"{wrapper}<"
        if value.startswith(prefix) and value.endswith(">"):
            return {"kind": wrapper, "value": parse_type(value[len(prefix) : -1])}
    if value.startswith("result<") and value.endswith(">"):
        parts = split_top_level(value[7:-1])
        return {
            "kind": "result",
            "ok": parse_type(parts[0]) if parts and parts[0] != "_" else None,
            "error": parse_type(parts[1]) if len(parts) > 1 else None,
        }
    scalars = {"bool", "string", "u8", "u16", "u32", "u64", "s8", "s16", "s32", "s64", "f32", "f64"}
    if value in scalars:
        return {"kind": "scalar", "name": value}
    return {"kind": "named", "name": value}


def parse_arguments(value: str) -> list[dict]:
    arguments = []
    for argument in split_top_level(value):
        if not argument:
            continue
        name, type_name = argument.split(":", 1)
        arguments.append({"name": name.strip(), "type": parse_type(type_name)})
    return arguments


def parse_declarations(body: str) -> tuple[dict, dict, dict, list[dict], list[dict]]:
    records: dict = {}
    variants: dict = {}
    enums: dict = {}
    resources: list[dict] = []
    functions: list[dict] = []
    occupied: list[tuple[int, int]] = []
    for match in DECLARATION.finditer(body):
        start = match.start()
        if any(begin <= start < end for begin, end in occupied):
            continue
        end = matching_brace(body, match.end() - 1)
        occupied.append((start, end + 1))
        kind, name = match.group(1), match.group(2)
        content = body[match.end() : end].strip()
        if kind == "record":
            fields = []
            for field in split_top_level(content):
                if not field:
                    continue
                field_name, field_type = field.split(":", 1)
                fields.append({"name": field_name.strip(), "type": parse_type(field_type)})
            records[name] = {"fields": fields}
        elif kind == "variant":
            values = []
            for item in split_top_level(content):
                item = item.strip()
                if not item:
                    continue
                if "(" in item and item.endswith(")"):
                    variant_name, payload = item.split("(", 1)
                    values.append({"name": variant_name.strip(), "type": parse_type(payload[:-1])})
                else:
                    values.append({"name": item, "type": None})
            variants[name] = {"values": values}
        elif kind == "enum":
            enums[name] = {"values": [item.strip() for item in split_top_level(content) if item.strip()]}
        else:
            resources.append({"name": name, "functions": parse_functions(content)})
    functions.extend(parse_functions(body, occupied))
    return records, variants, enums, resources, functions


def parse_functions(body: str, occupied: list[tuple[int, int]] | None = None) -> list[dict]:
    functions = []
    for match in FUNCTION.finditer(body):
        if occupied and any(begin <= match.start() < end for begin, end in occupied):
            continue
        functions.append(
            {
                "name": match.group(1),
                "arguments": parse_arguments(match.group(2)),
                "result": parse_type(match.group(3)) if match.group(3) else None,
            }
        )
    return functions


def parse_file(path: Path, relative: str) -> dict:
    source = re.sub(r"//.*", "", path.read_text())
    package = re.search(r"package\s+([^;]+);", source)
    interfaces = {}
    worlds = {}
    for match in INTERFACE.finditer(source):
        end = matching_brace(source, match.end() - 1)
        name, body = match.group(2), source[match.end() : end]
        if match.group(1) == "interface":
            records, variants, enums, resources, functions = parse_declarations(body)
            interfaces[name] = {
                "records": records,
                "variants": variants,
                "enums": enums,
                "resources": resources,
                "functions": functions,
                "uses": {
                    imported.strip(): source
                    for source, names in re.findall(r"use\s+([A-Za-z0-9-]+)\.\{([^}]+)\};", body)
                    for imported in names.split(",")
                    if imported.strip()
                },
            }
        else:
            imports = re.findall(r"import\s+([A-Za-z0-9-]+)\s*;", body)
            exports = re.findall(r"export\s+([A-Za-z0-9-]+)\s*;", body)
            worlds[name] = {"imports": imports, "exports": exports}
    return {"file": relative, "package": package.group(1).strip() if package else None, "interfaces": interfaces, "worlds": worlds}


def report(schema: dict) -> str:
    lines = [
        "# Generated M3 WIT compatibility report",
        "",
        "This file is generated from the active WIT files; it is not a second contract.",
        "",
        "| File | Interface | Records | Variants | Enums | Resources | Functions |",
        "|---|---|---:|---:|---:|---:|---:|",
    ]
    for contract in schema["contracts"]:
        for name, interface in contract["interfaces"].items():
            lines.append(
                f"| `{contract['file']}` | `{name}` | {len(interface['records'])} | "
                f"{len(interface['variants'])} | {len(interface['enums'])} | "
                f"{len(interface['resources'])} | {len(interface['functions'])} |"
            )
    lines.extend(
        [
            "",
            "## Native mapping",
            "",
            "- scalar values map to JSON scalars;",
            "- records map to JSON objects with required fields and nullable option fields;",
            "- lists map to JSON arrays;",
            "- enums map to strings;",
            "- variants map to `{\"tag\": string, \"value\": value}` when a payload exists;",
            "- results map to exactly one of `{\"ok\": value}` or `{\"error\": value}`;",
            "- Component resources remain opaque invocation-scoped references; no ABI object is generated.",
            "",
            "The generated schema has no Wasmtime or Component ABI dependency. Large-body/resource lifetime details remain pre-freeze follow-ups.",
        ]
    )
    return "\n".join(lines) + "\n"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo-root", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    args = parser.parse_args()
    contracts = [
        parse_file(args.repo_root / "plugin-api/wit/input.wit", "plugin-api/wit/input.wit"),
        parse_file(args.repo_root / "plugin-api/wit/broadcast.wit", "plugin-api/wit/broadcast.wit"),
    ]
    schema = {"schema_version": 1, "package": "stashd:plugin@0.1.0", "contracts": contracts}
    args.output_dir.mkdir(parents=True, exist_ok=True)
    (args.output_dir / "wit-schema.json").write_text(json.dumps(schema, indent=2, sort_keys=True) + "\n")
    (args.output_dir / "compatibility-report.md").write_text(report(schema))


if __name__ == "__main__":
    main()
