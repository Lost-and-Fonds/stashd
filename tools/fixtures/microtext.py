#!/usr/bin/env python3
"""Generate and independently verify deterministic tiny-file preservation corpora.

The reference manifest is written outside the source directory, so verification
never needs the original files or a running Stashd installation.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import sys
from pathlib import Path

FORMAT = "stashd-microtext-v1"
REFERENCE_FORMAT = "stashd-microtext-reference-v1"
DEFAULT_SEED = "stashd-microtext-v1"
WORDS = (
    "amber", "ancient", "anchor", "apricot", "archive", "autumn", "beacon", "bicycle",
    "birch", "brass", "candle", "canvas", "cedar", "circuit", "cloud", "compass",
    "copper", "coral", "curious", "delta", "engine", "feather", "fern", "forest",
    "garden", "harbour", "hollow", "island", "jasmine", "lantern", "little", "marble",
    "meadow", "mirror", "moss", "night", "orbit", "paper", "pebble", "pocket",
    "quiet", "river", "rocket", "silver", "sparrow", "stone", "tangent", "velvet",
    "violet", "willow", "window", "winter", "wooden", "yellow", "zephyr",
)
PATH_PATTERN = re.compile(r"[0-9a-f]/[0-9a-f]/[0-9a-f]{16}\.txt\Z")
SHA256_PATTERN = re.compile(r"[0-9a-f]{64}\Z")
TEXT_PATTERN = re.compile(r"entry[0-9]{6,}(?: [a-z]+){4,9}\n\Z")


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def content_for(index: int, seed: str) -> bytes:
    """Make 5–10 space-separated words, including a unique entry identifier."""
    prefix = f"{FORMAT}:{seed}:{index}:".encode("utf-8")
    count = 5 + int.from_bytes(hashlib.sha256(prefix + b"length").digest()[:2], "big") % 6
    words = [f"entry{index:06d}"]
    for slot in range(count - 1):
        digest = hashlib.sha256(prefix + str(slot).encode("ascii")).digest()
        words.append(WORDS[int.from_bytes(digest[:4], "big") % len(WORDS)])
    return (" ".join(words) + "\n").encode("utf-8")


def manifest_bytes(manifest: dict) -> bytes:
    """Keep the generated manifest byte-for-byte reproducible across machines."""
    return (json.dumps(manifest, sort_keys=True, separators=(",", ":"), ensure_ascii=True) + "\n").encode("ascii")


def generate(directory: Path, manifest_path: Path, count: int, seed: str, progress=None) -> str:
    """Create new fixture files and an external manifest; never replace existing data."""
    directory = directory.absolute()
    manifest_path = manifest_path.absolute()
    if count < 1 or count > 1_000_000:
        raise ValueError("--count must be between 1 and 1,000,000")
    if not seed or any(ord(char) < 32 for char in seed):
        raise ValueError("--seed must be nonempty and contain no control characters")
    if directory == manifest_path or directory in manifest_path.parents:
        raise ValueError("manifest must be outside the generated source directory")
    if directory.exists() or directory.is_symlink() or manifest_path.exists() or manifest_path.is_symlink():
        raise ValueError("source directory and manifest must not already exist")

    directory.mkdir(parents=True)
    entries = []
    paths = set()
    for index in range(count):
        contents = content_for(index, seed)
        digest = sha256(contents)
        relative = f"{digest[0]}/{digest[1]}/{digest[:16]}.txt"
        if relative in paths:
            raise ValueError(f"truncated filename hash collision: {relative}")
        paths.add(relative)
        path = directory / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        with path.open("xb") as stream:
            stream.write(contents)
        entries.append({"index": index, "path": relative, "bytes": len(contents), "sha256": digest})
        if progress is not None:
            progress(len(entries))

    manifest = {"format": FORMAT, "seed": seed, "count": count, "entries": entries}
    raw = manifest_bytes(manifest)
    manifest_path.parent.mkdir(parents=True, exist_ok=True)
    with manifest_path.open("xb") as stream:
        stream.write(raw)
    return sha256(raw)


def verify(directory: Path, manifest_path: Path, reference_path: Path | None = None, progress=None) -> tuple[int, list[str]]:
    """Check a directory against independently stored digests, not against its source."""
    errors: list[str] = []
    if not directory.is_dir() or directory.is_symlink():
        return 0, ["output directory does not exist or is a symlink"]
    try:
        raw = manifest_path.read_bytes()
        manifest = json.loads(raw)
        if not isinstance(manifest, dict) or set(manifest) != {"format", "seed", "count", "entries"}:
            raise ValueError("unexpected manifest structure")
        if manifest["format"] != FORMAT or not isinstance(manifest["seed"], str):
            raise ValueError("unsupported manifest format or seed")
        if type(manifest["count"]) is not int or manifest["count"] < 1 or not isinstance(manifest["entries"], list):
            raise ValueError("invalid manifest count or entries")
        if len(manifest["entries"]) != manifest["count"]:
            raise ValueError("manifest entry count mismatch")
        if reference_path is not None:
            reference = json.loads(reference_path.read_bytes())
            if (not isinstance(reference, dict) or reference.get("format") != REFERENCE_FORMAT
                or reference.get("seed") != manifest["seed"] or reference.get("count") != manifest["count"]
                or reference.get("manifest_sha256") != sha256(raw)):
                raise ValueError("manifest differs from the committed reference digest")
    except (OSError, UnicodeError, ValueError, TypeError, KeyError) as exc:
        return 0, [f"invalid or untrusted manifest: {exc}"]

    wanted: set[str] = set()
    indices: set[int] = set()
    dirs: set[str] = set()
    checked = 0
    verified = 0
    for entry in manifest["entries"]:
        previous_errors = len(errors)
        if not isinstance(entry, dict) or set(entry) != {"index", "path", "bytes", "sha256"}:
            errors.append("invalid manifest entry shape")
            continue
        index, relative, size, digest = entry["index"], entry["path"], entry["bytes"], entry["sha256"]
        if (type(index) is not int or index < 0 or index >= manifest["count"] or index in indices
            or type(size) is not int or size < 1 or not isinstance(relative, str) or not PATH_PATTERN.fullmatch(relative)
            or not isinstance(digest, str) or not SHA256_PATTERN.fullmatch(digest)
            or relative != f"{digest[0]}/{digest[1]}/{digest[:16]}.txt" or relative in wanted):
            errors.append(f"invalid or duplicate manifest entry: {entry!r}")
            continue
        indices.add(index)
        wanted.add(relative)
        dirs.update((digest[0], f"{digest[0]}/{digest[1]}"))
        path = directory / relative
        if path.is_symlink() or not path.is_file():
            errors.append(f"missing or unsafe file: {relative}")
            continue
        try:
            contents = path.read_bytes()
            text = contents.decode("utf-8")
        except (OSError, UnicodeError) as exc:
            errors.append(f"cannot read or decode {relative}: {exc}")
            continue
        if len(contents) != size or sha256(contents) != digest:
            errors.append(f"content/size mismatch: {relative}")
        if not TEXT_PATTERN.fullmatch(text) or text.split(" ", 1)[0] != f"entry{index:06d}":
            errors.append(f"word-count or entry-format mismatch: {relative}")
        checked += 1
        if len(errors) == previous_errors:
            verified += 1
            if progress is not None:
                progress(verified)

    if len(indices) != manifest["count"]:
        errors.append("incomplete or duplicate manifest indices")
    for root, subdirs, filenames in os.walk(directory, followlinks=False):
        relroot = Path(root).relative_to(directory).as_posix()
        for name in subdirs:
            child = Path(root) / name
            relative = name if relroot == "." else f"{relroot}/{name}"
            if child.is_symlink() or relative not in dirs:
                errors.append(f"unexpected or unsafe directory: {relative}")
        for name in filenames:
            child = Path(root) / name
            relative = name if relroot == "." else f"{relroot}/{name}"
            if child.is_symlink() or relative not in wanted or not child.is_file():
                errors.append(f"unexpected or unsafe file: {relative}")
    return checked, errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    generate_parser = commands.add_parser("generate", help="create a new deterministic source corpus")
    generate_parser.add_argument("--directory", required=True, type=Path)
    generate_parser.add_argument("--manifest", required=True, type=Path)
    generate_parser.add_argument("--count", type=int, default=10_000)
    generate_parser.add_argument("--seed", default=DEFAULT_SEED)
    generate_parser.add_argument("--progress", action="store_true", help="emit completed-file counts as JSON lines")
    verify_parser = commands.add_parser("verify", help="validate a copied corpus without its original")
    verify_parser.add_argument("--directory", required=True, type=Path)
    verify_parser.add_argument("--manifest", required=True, type=Path)
    verify_parser.add_argument("--reference", type=Path, help="optional committed reference digest")
    verify_parser.add_argument("--progress", action="store_true", help="emit checked-file counts as JSON lines")
    args = parser.parse_args(argv)
    progress = (lambda completed: print(json.dumps({"completed": completed}), flush=True)) if args.progress else None
    try:
        if args.command == "generate":
            digest = generate(args.directory, args.manifest, args.count, args.seed, progress)
            print(f"Generated {args.count} files; manifest SHA-256 {digest}")
            return 0
        checked, errors = verify(args.directory, args.manifest, args.reference, progress)
    except (OSError, ValueError) as exc:
        print(f"microtext: {exc}", file=sys.stderr)
        return 1
    for error in errors[:20]:
        print(f"microtext: {error}", file=sys.stderr)
    if errors:
        print(f"microtext: FAILED ({len(errors)} problems; {checked} files checked)", file=sys.stderr)
        return 1
    print(f"Verified {checked} files against external manifest")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
