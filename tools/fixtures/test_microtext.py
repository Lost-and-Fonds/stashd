"""Regression checks for the source-independent Microtext fixture."""

import hashlib
import json
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import microtext


class MicrotextTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="stashd-microtext-test-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / "source"
        self.manifest = self.root / "manifest.json"
        microtext.generate(self.source, self.manifest, 37, microtext.DEFAULT_SEED)
        self.entries = json.loads(self.manifest.read_text())["entries"]

    def test_self_verifying_filename_and_word_count(self):
        self.assertEqual(len(self.entries), 37)
        for entry in self.entries:
            payload = (self.source / entry["path"]).read_bytes()
            self.assertEqual(hashlib.sha256(payload).hexdigest(), entry["sha256"])
            self.assertEqual(Path(entry["path"]).stem, entry["sha256"][:16])
            self.assertGreaterEqual(len(payload.decode("utf-8").split()), 5)
            self.assertLessEqual(len(payload.decode("utf-8").split()), 10)
        self.assertEqual(microtext.verify(self.source, self.manifest), (37, []))

    def test_generator_is_reproducible(self):
        alternate_manifest = self.root / "repeat.json"
        alternate_source = self.root / "repeat"
        microtext.generate(alternate_source, alternate_manifest, 37, microtext.DEFAULT_SEED)
        self.assertEqual(self.manifest.read_bytes(), alternate_manifest.read_bytes())
        self.assertEqual(microtext.verify(alternate_source, alternate_manifest), (37, []))

    def test_progress_follows_real_files_and_rejects_corrupt_bytes(self):
        generated = []
        other = self.root / "progress-source"
        manifest = self.root / "progress-manifest.json"
        microtext.generate(other, manifest, 3, microtext.DEFAULT_SEED,
                           lambda n: generated.append((n, len(list(other.rglob("*.txt"))))))
        self.assertEqual(generated, [(1, 1), (2, 2), (3, 3)])
        checked = []
        self.assertEqual(microtext.verify(other, manifest, progress=checked.append), (3, []))
        self.assertEqual(checked, [1, 2, 3])
        entry = json.loads(manifest.read_text())["entries"][0]
        (other / entry["path"]).write_bytes(b"corrupt\n")
        checked.clear()
        self.assertTrue(microtext.verify(other, manifest, progress=checked.append)[1])
        self.assertEqual(checked, [1, 2])

    def test_copy_survives_source_deletion_and_rejects_mutation(self):
        broadcast = self.root / "broadcast"
        shutil.copytree(self.source, broadcast)
        shutil.rmtree(self.source)
        self.assertEqual(microtext.verify(broadcast, self.manifest), (37, []))
        (broadcast / self.entries[0]["path"]).write_bytes(b"the wrong bytes\n")
        self.assertTrue(microtext.verify(broadcast, self.manifest)[1])

    def test_missing_and_unexpected_files_fail(self):
        (self.source / self.entries[0]["path"]).unlink()
        self.assertTrue(microtext.verify(self.source, self.manifest)[1])
        (self.source / "surprise.txt").write_text("unexpected\n")
        self.assertTrue(any("unexpected" in problem for problem in microtext.verify(self.source, self.manifest)[1]))

    def test_reference_pin_rejects_manifest_redefinition(self):
        reference = self.root / "reference.json"
        reference.write_text(json.dumps({
            "format": microtext.REFERENCE_FORMAT,
            "seed": microtext.DEFAULT_SEED,
            "count": 37,
            "manifest_sha256": hashlib.sha256(self.manifest.read_bytes()).hexdigest(),
        }))
        self.assertEqual(microtext.verify(self.source, self.manifest, reference), (37, []))
        changed = json.loads(self.manifest.read_text())
        changed["entries"][0]["bytes"] += 1
        self.manifest.write_bytes(microtext.manifest_bytes(changed))
        self.assertTrue(any("committed reference" in problem for problem in microtext.verify(self.source, self.manifest, reference)[1]))

    def test_no_overwrite_or_manifest_inside_source(self):
        with self.assertRaises(ValueError):
            microtext.generate(self.source, self.root / "other.json", 1, microtext.DEFAULT_SEED)
        with self.assertRaises(ValueError):
            microtext.generate(self.root / "new", self.root / "new" / "manifest.json", 1, microtext.DEFAULT_SEED)

    def test_symlink_and_unexpected_directories_fail(self):
        extra = self.source / "extra"
        extra.mkdir()
        self.assertTrue(any("unexpected" in problem for problem in microtext.verify(self.source, self.manifest)[1]))
        extra.rmdir()
        target = self.source / self.entries[0]["path"]
        target.unlink()
        target.symlink_to(self.source / self.entries[1]["path"])
        self.assertTrue(any("unsafe" in problem for problem in microtext.verify(self.source, self.manifest)[1]))

    def test_cli_exits_nonzero_on_corruption(self):
        program = Path(microtext.__file__)
        self.assertEqual(subprocess.run([sys.executable, str(program), "verify", "--directory", str(self.source), "--manifest", str(self.manifest)], capture_output=True).returncode, 0)
        (self.source / self.entries[0]["path"]).write_bytes(b"corrupted\n")
        self.assertNotEqual(subprocess.run([sys.executable, str(program), "verify", "--directory", str(self.source), "--manifest", str(self.manifest)], capture_output=True).returncode, 0)


if __name__ == "__main__":
    unittest.main()
