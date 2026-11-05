#!/usr/bin/env python3
"""Drive the lifecycle probe in a controlling PTY, including real terminal Ctrl+C."""

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
    php, script, scenario, evidence, workers, columns, cancellation = sys.argv[1:]
    pid, terminal = pty.fork()
    if pid == 0:
        fcntl.ioctl(0, termios.TIOCSWINSZ, struct.pack("HHHH", 24, int(columns), 0, 0))
        os.execvpe(php, [php, "-d", "lerd.devtools_flag=/disabled-for-worker-lifecycle", script, scenario, evidence, workers], {**os.environ, "TERM": "xterm-256color"})

    output = bytearray()
    sent = False
    status = None
    deadline = time.monotonic() + 75
    try:
        while time.monotonic() < deadline:
            if select.select([terminal], [], [], 0.05)[0]:
                try:
                    chunk = os.read(terminal, 65536)
                except OSError as error:
                    if error.errno != errno.EIO:
                        raise
                    chunk = b""
                output.extend(chunk)
                if b"\x1b[6n" in chunk:
                    os.write(terminal, b"\x1b[1;1R")
            if cancellation != "none" and not sent and Path(evidence).is_file():
                report = json.loads(Path(evidence).read_text())
                if report.get("owner_pid") == pid and report.get("at_active_boundary"):
                    if cancellation == "ctrl-c":
                        os.write(terminal, b"\x03")
                    else:
                        os.killpg(pid, signal.SIGTERM)
                    sent = True
            exited, result = os.waitpid(pid, os.WNOHANG)
            if exited:
                status = result
                break
        assert status is not None, "Lifecycle probe exceeded PTY deadline"
        expected = 0 if scenario == "pass" else 1
        assert os.waitstatus_to_exitcode(status) == expected, output.decode(errors="replace")
        if cancellation != "none":
            assert sent, "Signal was not delivered at an active handler boundary"
        assert b"\x1b[" in output, "Interactive run did not render through a decorated PTY"
        plain = re.sub(r"\x1b\[[0-?]*[ -/]*[@-~]", "", output.decode(errors="replace"))
        summaries = ["✔ Lifecycle · workers stopped and reaped"]
        if scenario != "startup":
            summaries.append("✔ Lifecycle · workers survived an empty queue")
        if scenario == "pass":
            summaries.extend(["✔ Lifecycle · completed discovery job 1", "✔ Lifecycle · completed discovery job 2"])
        else:
            assert "✔ Lifecycle · completed discovery job" not in plain, plain
        for summary in summaries:
            assert plain.count(summary) == 1, plain
        assert plain.count("✔ ") == len(summaries), plain
        print(f"PTY {columns} columns / {scenario} / {cancellation}: exit {expected}")
    finally:
        Path(evidence + ".pty").write_bytes(output)
        if status is None:
            os.killpg(pid, signal.SIGTERM)
            until = time.monotonic() + 8
            while time.monotonic() < until:
                exited, _ = os.waitpid(pid, os.WNOHANG)
                if exited:
                    break
                time.sleep(0.05)
            else:
                os.killpg(pid, signal.SIGKILL)
                os.waitpid(pid, 0)
        os.close(terminal)


if __name__ == "__main__":
    main()
