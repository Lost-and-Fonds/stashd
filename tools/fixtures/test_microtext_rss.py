"""Small, platform-guarded regressions for the independent RSS sampler."""

from __future__ import annotations

import os
import subprocess
import sys
import time
import unittest

from process_tree_rss import process_status, sample


@unittest.skipUnless(os.path.isdir("/proc"), "Linux /proc is required for PR5 resource measurement")
class ProcessTreeMemoryTest(unittest.TestCase):
    def test_current_process_has_a_readable_parent_and_resident_memory(self) -> None:
        status = process_status(os.getpid())
        self.assertIsNotNone(status)
        assert status is not None
        parent, resident = status
        self.assertGreater(parent, 0)
        self.assertGreater(resident, 0)

    def test_child_is_counted_but_an_excluded_sampler_is_not(self) -> None:
        child = subprocess.Popen([sys.executable, "-c", "import time; time.sleep(2)"])
        try:
            deadline = time.monotonic() + 1.0
            count = 0
            while time.monotonic() < deadline:
                _, count = sample(os.getpid())
                if count >= 2:
                    break
                time.sleep(0.01)
            self.assertGreaterEqual(count, 2)
            _, without_self = sample(os.getpid(), exclude=os.getpid())
            self.assertGreaterEqual(without_self, 1)
        finally:
            child.terminate()
            child.wait(timeout=5)


if __name__ == "__main__":
    unittest.main()
