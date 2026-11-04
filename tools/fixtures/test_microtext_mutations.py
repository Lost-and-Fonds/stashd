"""Regression tests for the independent, deliberately unpinned mutation fixture."""

from __future__ import annotations

import json
import shutil
import tempfile
import unittest
from pathlib import Path

from microtext import generate, verify as verify_original
from microtext_mutations import mutate, verify_snapshot


class MutationFixturesTest(unittest.TestCase):
    """Keep changed-path and renamed-byte expectations independent of Stashd."""

    def test_disjoint_mutations_and_two_distinct_snapshots(self) -> None:
        """Changed bytes stay at a path, while renamed and missing origins stay archival."""
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            source = base / "source"
            original = base / "original.json"
            after_source = base / "after-source.json"
            after_preserved = base / "after-preserved.json"
            operations_file = base / "operations.json"
            generate(source, original, count=8, seed="mutation-regression")
            before = json.loads(original.read_bytes())
            changes = mutate(source, original, after_source, after_preserved, operations_file,
                             added=2, changed=2, renamed=1, removed=1)
            self.assertEqual({k: len(changes[k]) for k in ("added", "changed", "renamed", "removed")},
                             {"added": 2, "changed": 2, "renamed": 1, "removed": 1})
            self.assertEqual(verify_snapshot(source, after_source)[1], [])
            self.assertEqual(json.loads(after_source.read_bytes())["count"], 9)
            self.assertEqual(json.loads(after_preserved.read_bytes())["count"], 11)
            self.assertEqual(verify_original(source, original)[1] != [], True)

            changed = changes["changed"][0]
            original_record = next(row for row in before["entries"] if row["path"] == changed["path"])
            self.assertEqual(original_record["sha256"], changed["old_sha256"])
            self.assertNotEqual(changed["old_sha256"], changed["new_sha256"])
            renamed = changes["renamed"][0]
            self.assertNotIn(renamed["from"], [row["path"] for row in json.loads(after_source.read_bytes())["entries"]])
            self.assertIn(renamed["from"], [row["path"] for row in json.loads(after_preserved.read_bytes())["entries"]])
            self.assertIn(renamed["to"], [row["path"] for row in json.loads(after_preserved.read_bytes())["entries"]])
            removed = changes["removed"][0]
            self.assertNotIn(removed["path"], [row["path"] for row in json.loads(after_source.read_bytes())["entries"]])
            self.assertIn(removed["path"], [row["path"] for row in json.loads(after_preserved.read_bytes())["entries"]])

    def test_external_verifier_rejects_head_instead_of_historical_bytes(self) -> None:
        """A latest-head substitution must fail against the frozen original manifest."""
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            source = base / "source"
            original = base / "original.json"
            after_source = base / "after-source.json"
            after_preserved = base / "after-preserved.json"
            operations_file = base / "operations.json"
            generate(source, original, count=7, seed="historical-negative")
            restored = base / "wrong-historical-output"
            shutil.copytree(source, restored)
            self.assertEqual(verify_original(restored, original)[1], [])
            mutation = mutate(source, original, after_source, after_preserved, operations_file,
                              added=1, changed=2, renamed=1, removed=1)
            # Substitute ONLY the modified files' latest bytes in a once-correct old
            # output. This tests the independent historical oracle, not Stashd.
            for change in mutation["changed"]:
                (restored / change["path"]).write_bytes((source / change["path"]).read_bytes())
            _, failures = verify_original(restored, original)
            self.assertTrue(failures, "The frozen original oracle must reject substituted latest bytes.")
            _, failures = verify_snapshot(restored, after_preserved)
            self.assertTrue(failures, "The post-mutation output must include additions and renamed histories.")

    def test_verifier_rejects_extra_paths_and_symlinks(self) -> None:
        """A checksum-correct directory is still wrong if it contains extra or unsafe paths."""
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            source = base / "source"
            original = base / "original.json"
            after_source = base / "after-source.json"
            after_preserved = base / "after-preserved.json"
            operations = base / "operations.json"
            generate(source, original, count=6, seed="extra-negative")
            mutate(source, original, after_source, after_preserved, operations,
                   added=1, changed=1, renamed=1, removed=1)
            self.assertEqual(verify_snapshot(source, after_source)[1], [])
            (source / "extra.txt").write_text("unexpected\n")
            self.assertTrue(verify_snapshot(source, after_source)[1])
            (source / "extra.txt").unlink()
            (source / "unexpected-link").symlink_to("missing-target")
            self.assertTrue(verify_snapshot(source, after_source)[1])


if __name__ == "__main__":
    unittest.main()
