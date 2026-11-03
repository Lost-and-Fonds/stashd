#!/usr/bin/env python3
"""Create a separate deterministic mutation corpus and verify its independent snapshots.

The pinned Microtext v1 generator/reference are never modified. After-source and
after-preservation expectations differ because missing and renamed-away files
remain preserved Items, even though they no longer exist in the Input directory.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
from pathlib import Path, PurePosixPath

from microtext import content_for, verify as verify_original

FORMAT = "stashd-microtext-mutations-v1"
DIGEST = re.compile(r"[0-9a-f]{64}\Z")


def _safe_path(value: str) -> bool:
    """Accept only ordinary, relative, POSIX file paths with no dot components."""
    return (
        isinstance(value, str)
        and bool(value)
        and "\\" not in value
        and not value.startswith("/")
        and all(part not in ("", ".", "..") for part in value.split("/"))
        and all(ch.isascii() and (ch.isalnum() or ch in "._-/") for ch in value)
        and PurePosixPath(value).as_posix() == value
    )


def _entry(path: str, data: bytes) -> dict:
    """Describe expected bytes without requiring their source to survive."""
    if not _safe_path(path):
        raise ValueError(f"unsafe mutation fixture path: {path!r}")
    return {"path": path, "bytes": len(data), "sha256": hashlib.sha256(data).hexdigest()}


def _snapshot(phase: str, entries: dict[str, dict]) -> dict:
    """Produce a deterministic, sorted manifest for external byte verification."""
    return {
        "format": FORMAT,
        "phase": phase,
        "count": len(entries),
        "entries": [entries[path] for path in sorted(entries)],
    }


def _save(path: Path, document: dict) -> None:
    """Write each independent manifest exactly once outside the source directory."""
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("x", encoding="ascii") as stream:
        json.dump(document, stream, sort_keys=True, separators=(",", ":"), ensure_ascii=True)
        stream.write("\n")


def mutate(
    directory: Path,
    original_manifest: Path,
    source_manifest: Path,
    preserved_manifest: Path,
    operations_manifest: Path,
    added: int,
    changed: int,
    renamed: int,
    removed: int,
) -> dict:
    """Apply disjoint deterministic changes and record both future output truths.

    Changed files keep their old content-derived filename on purpose: path
    identity, not hash identity, decides whether Stashd appends an Item revision.
    Renames preserve the exact original bytes but move them under renamed/.
    """
    if any(number < 0 for number in (added, changed, renamed, removed)):
        raise ValueError("mutation counts cannot be negative")
    if len({str(path.resolve()) for path in (original_manifest, source_manifest, preserved_manifest, operations_manifest)}) != 4:
        raise ValueError("all mutation manifests must use different paths")
    if any(path == directory or directory in path.parents for path in
           (original_manifest, source_manifest, preserved_manifest, operations_manifest)):
        raise ValueError("all manifests must remain outside the source directory")
    if any(path.exists() or path.is_symlink() for path in (source_manifest, preserved_manifest, operations_manifest)):
        raise ValueError("mutation output manifests must not already exist")

    count, errors = verify_original(directory, original_manifest)
    if errors:
        raise ValueError("original corpus must verify before mutation: " + "; ".join(errors[:2]))
    original = json.loads(original_manifest.read_text(encoding="ascii"))
    baseline = original["entries"]
    if count != len(baseline) or changed + renamed + removed > count:
        raise ValueError("disjoint mutation groups exceed the original corpus")

    # Distinct original indices keep all four cases mutually exclusive.
    changed_rows = baseline[:changed]
    renamed_rows = baseline[changed:changed + renamed]
    removed_rows = baseline[changed + renamed:changed + renamed + removed]
    source_entries = {row["path"]: {"path": row["path"], "bytes": row["bytes"], "sha256": row["sha256"]}
                      for row in baseline}
    preserved_entries = dict(source_entries)
    operations = {"format": FORMAT, "added": [], "changed": [], "renamed": [], "removed": []}

    for row in changed_rows:
        relative = row["path"]
        next_bytes = content_for(row["index"], original["seed"] + "-mutation-changed-v1")
        current = directory / relative
        if next_bytes == current.read_bytes():
            raise ValueError("changed case must genuinely change the bytes")
        current.write_bytes(next_bytes)
        source_entries[relative] = preserved_entries[relative] = _entry(relative, next_bytes)
        operations["changed"].append({"path": relative, "old_sha256": row["sha256"], "new_sha256": source_entries[relative]["sha256"]})

    for row in renamed_rows:
        relative = row["path"]
        destination = "renamed/" + relative
        if destination in source_entries:
            raise ValueError("renamed destination collides with the baseline")
        target = directory / destination
        target.parent.mkdir(parents=True, exist_ok=True)
        (directory / relative).rename(target)
        source_entries.pop(relative)
        source_entries[destination] = preserved_entries[destination] = _entry(destination, target.read_bytes())
        operations["renamed"].append({"from": relative, "to": destination, "sha256": row["sha256"]})

    for row in removed_rows:
        (directory / row["path"]).unlink()
        source_entries.pop(row["path"])
        operations["removed"].append({"path": row["path"], "sha256": row["sha256"]})

    for index in range(count, count + added):
        data = content_for(index, original["seed"] + "-mutation-added-v1")
        digest = hashlib.sha256(data).hexdigest()
        relative = f"{digest[0]}/{digest[1]}/{digest[:16]}.txt"
        if relative in preserved_entries:
            raise ValueError("new file collides with a preserved source path")
        target = directory / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        with target.open("xb") as stream:
            stream.write(data)
        source_entries[relative] = preserved_entries[relative] = _entry(relative, data)
        operations["added"].append({"path": relative, "sha256": digest})

    # Remove only now-empty source directories. The external verifier deliberately
    # rejects surprising directory entries, not just unexpected files.
    for parent, _, _ in os.walk(directory, topdown=False, followlinks=False):
        candidate = Path(parent)
        if candidate != directory and not any(candidate.iterdir()):
            candidate.rmdir()

    _save(source_manifest, _snapshot("after-source", source_entries))
    _save(preserved_manifest, _snapshot("after-preservation", preserved_entries))
    _save(operations_manifest, operations)
    return operations


def verify_snapshot(directory: Path, manifest_path: Path) -> tuple[int, list[str]]:
    """Independently check every byte, omission and extra path in a snapshot.

    Source deletion is fine: the verifier inspects only its chosen directory
    and a separately saved manifest, never a Vault reader or SQL result.
    """
    errors: list[str] = []
    if not directory.is_dir() or directory.is_symlink():
        return 0, ["output root is missing or is a symlink"]
    try:
        data = json.loads(manifest_path.read_bytes())
        if (not isinstance(data, dict) or set(data) != {"format", "phase", "count", "entries"}
                or data["format"] != FORMAT or data["phase"] not in ("after-source", "after-preservation")
                or type(data["count"]) is not int or data["count"] < 0
                or not isinstance(data["entries"], list) or len(data["entries"]) != data["count"]):
            raise ValueError("malformed mutation snapshot")
    except (OSError, UnicodeError, ValueError, TypeError, KeyError) as exc:
        return 0, [f"cannot trust mutation snapshot: {exc}"]

    expected: set[str] = set()
    allowed_dirs: set[str] = set()
    checked = 0
    for row in data["entries"]:
        if (not isinstance(row, dict) or set(row) != {"path", "bytes", "sha256"}
                or not _safe_path(row["path"]) or type(row["bytes"]) is not int
                or row["bytes"] <= 0 or not isinstance(row["sha256"], str)
                or not DIGEST.fullmatch(row["sha256"]) or row["path"] in expected):
            errors.append("invalid or duplicated expected file")
            continue
        relative = row["path"]
        expected.add(relative)
        parents = PurePosixPath(relative).parents
        allowed_dirs.update(str(parent) for parent in parents if str(parent) != ".")
        path = directory / relative
        if not path.is_file() or path.is_symlink():
            errors.append(f"missing or unsafe: {relative}")
            continue
        try:
            payload = path.read_bytes()
        except OSError:
            errors.append(f"unreadable: {relative}")
            continue
        if len(payload) != row["bytes"] or hashlib.sha256(payload).hexdigest() != row["sha256"]:
            errors.append(f"changed bytes: {relative}")
        checked += 1

    if len(expected) != data["count"]:
        errors.append("manifest contains duplicate or invalid records")
    for root, dirs, files in os.walk(directory, followlinks=False):
        prefix = Path(root).relative_to(directory).as_posix()
        for name in dirs:
            relative = name if prefix == "." else prefix + "/" + name
            if (Path(root) / name).is_symlink() or relative not in allowed_dirs:
                errors.append(f"unexpected directory: {relative}")
        for name in files:
            relative = name if prefix == "." else prefix + "/" + name
            if (Path(root) / name).is_symlink() or relative not in expected:
                errors.append(f"unexpected file: {relative}")
    return checked, errors


def main() -> int:
    """Expose mutation and external verification as separate CLI operations."""
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    mutation = sub.add_parser("mutate")
    mutation.add_argument("--directory", required=True, type=Path)
    mutation.add_argument("--original-manifest", required=True, type=Path)
    mutation.add_argument("--after-source-manifest", required=True, type=Path)
    mutation.add_argument("--after-preserved-manifest", required=True, type=Path)
    mutation.add_argument("--operations-manifest", required=True, type=Path)
    mutation.add_argument("--add", type=int, default=100)
    mutation.add_argument("--change", type=int, default=200)
    mutation.add_argument("--rename", type=int, default=50)
    mutation.add_argument("--remove", type=int, default=30)
    verify = sub.add_parser("verify")
    verify.add_argument("--directory", required=True, type=Path)
    verify.add_argument("--manifest", required=True, type=Path)
    args = parser.parse_args()
    if args.command == "mutate":
        changes = mutate(args.directory, args.original_manifest, args.after_source_manifest,
                         args.after_preserved_manifest, args.operations_manifest,
                         args.add, args.change, args.rename, args.remove)
        print(json.dumps({key: len(value) for key, value in changes.items() if key != "format"}, sort_keys=True))
        return 0
    checked, failures = verify_snapshot(args.directory, args.manifest)
    print(json.dumps({"checked": checked, "errors": failures}, sort_keys=True))
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
