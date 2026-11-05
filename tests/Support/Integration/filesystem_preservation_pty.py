#!/usr/bin/env python3
"""Exercise the real CLI configuration, collapsing tasks and active cancellation in a controlling PTY."""

import errno
import fcntl
import json
import os
import pty
import re
import select
import signal
import struct
import sys
import termios
import time
from pathlib import Path


def main():
    php, directory, count, workers, columns, action, recording = sys.argv[1:]
    pid, terminal = pty.fork()
    if pid == 0:
        fcntl.ioctl(0, termios.TIOCSWINSZ, struct.pack("HHHH", 24, int(columns), 0, 0))
        os.execvpe(php, [php, "-d", "lerd.devtools_flag=/disabled-for-preservation-pty", "tempest",
                        "stress:filesystem-preserve", "--count=" + count, "--workers=" + workers,
                        "--report-dir=" + directory], {**os.environ, "TERM": "xterm-256color"})
    output = bytearray()
    prompts = ["File count", "Concurrent workers", "Report directory", "Begin filesystem preservation?"]
    answered = 0
    sent = False
    status = None
    report = None
    try:
        deadline = time.monotonic() + 180
        while time.monotonic() < deadline:
            if select.select([terminal], [], [], 0.02)[0]:
                try:
                    chunk = os.read(terminal, 65536)
                except OSError as error:
                    if error.errno != errno.EIO:
                        raise
                    chunk = b""
                output.extend(chunk)
                if b"\x1b[6n" in chunk:
                    os.write(terminal, b"\x1b[1;1R")
                plain = re.sub(r"\x1b\[[0-?]*[ -/]*[@-~]", "", output.decode(errors="replace"))
                if answered < len(prompts) and prompts[answered] in plain:
                    os.write(terminal, b"\r")
                    answered += 1
            if action != "none" and not sent:
                files = sorted(Path(directory).glob("filesystem-preservation-*.json"))
                if files:
                    report = json.loads(files[-1].read_text())
                    if report.get("owner_pid") == pid and report.get("phase") == "preserve" and 2 <= report.get("completed_jobs", 0) < int(count):
                        if action == "ctrl-c":
                            os.write(terminal, b"\x03")
                        else:
                            os.killpg(pid, signal.SIGTERM)
                        sent = True
            exited, result = os.waitpid(pid, os.WNOHANG)
            if exited:
                status = result
                break
        assert status is not None, "CLI did not finish before the PTY deadline"
        assert answered == 4, output.decode(errors="replace")
        assert os.waitstatus_to_exitcode(status) == (0 if action == "none" else 1), output.decode(errors="replace")
        if action != "none":
            assert sent, "Cancellation missed the real partial-completion boundary"
        plain = re.sub(r"\x1b\[[0-?]*[ -/]*[@-~]", "", output.decode(errors="replace"))
        assert "Warning:" not in plain and "Notice:" not in plain and "Fatal error:" not in plain, plain
        assert plain.count("✔ 01 / 09  Prepared") == 1, plain
        assert plain.count("✔ 02 / 09  Discovered") == 1, plain
        assert plain.count("✔ 09 / 09  Owned fixture cleaned") == 1, plain
        assert plain.count("✔ 03 / 09  Preserved") == (1 if action == "none" else 0), plain
        assert "04–08" in plain and "Not run" in plain, plain
        if action == "none":
            assert "Verify Items/Assets" in plain, plain
        print(f"CLI PTY: {count}/{workers}, {columns} columns, {action}: passed")
    finally:
        Path(recording).write_bytes(output)
        if status is None:
            os.killpg(pid, signal.SIGTERM)
            deadline = time.monotonic() + 10
            while time.monotonic() < deadline:
                if os.waitpid(pid, os.WNOHANG)[0]:
                    break
                time.sleep(0.02)
            else:
                os.killpg(pid, signal.SIGKILL)
                os.waitpid(pid, 0)
        os.close(terminal)


if __name__ == "__main__":
    main()
