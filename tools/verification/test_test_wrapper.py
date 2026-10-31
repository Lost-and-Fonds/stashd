"""Keep canonical QA usable on the host, in CI and in the minimal PHP container."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class WrapperTests(unittest.TestCase):
    """Exercise the POSIX wrapper while replacing only external runtime executables."""

    def invoke(self, ci, processes):
        with tempfile.TemporaryDirectory() as directory:
            runtime = Path(directory)
            for executable in ['php', 'lerd']:
                script = runtime / executable
                script.write_text('#!/bin/sh\nprintf "%s\\n" "' + executable + '" "$@"\n')
                script.chmod(0o700)
            result = subprocess.run(['/bin/sh', str(ROOT / 'bin/test.sh'), '--filter=proof'],
                                    env={**os.environ, 'PATH': str(runtime) + ':' + os.environ['PATH'],
                                         'CI': ci, 'STASHD_TEST_PROCESSES': processes},
                                    capture_output=True, text=True, timeout=5)
            self.assertEqual(0, result.returncode, result.stderr)
            return result.stdout.splitlines()

    def test_ci_serial_uses_canonical_php_entry(self):
        self.assertEqual(['php', 'vendor/bin/pest', '--filter=proof'], self.invoke('1', '1'))

    def test_ci_parallel_preserves_requested_arguments(self):
        self.assertEqual(['php', 'vendor/bin/pest', '--parallel', '--processes=3', '--filter=proof'], self.invoke('1', '3'))

    def test_host_routes_through_lerd(self):
        if Path('/run/.containerenv').exists():
            self.assertEqual('php', self.invoke('', '1')[0])
        else:
            self.assertEqual(['lerd', 'composer', 'test', '--', '--filter=proof'], self.invoke('', '1'))


if __name__ == '__main__':
    unittest.main()
