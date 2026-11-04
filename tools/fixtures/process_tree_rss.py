#!/usr/bin/env python3
"""Sample summed resident memory across a Linux process and its descendants.

This deliberately uses the standard library and /proc rather than app-reported
memory. Summed RSS can count shared memory more than once and does not measure
physical proportional set size or transient allocations between samples.
"""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import time


def process_status(pid: int) -> tuple[int, int] | None:
    """Return a process's parent and resident KiB, or None if it disappeared."""
    try:
        lines = Path(f"/proc/{pid}/status").read_text().splitlines()
    except (OSError, UnicodeError):
        return None
    values: dict[str, str] = {}
    for line in lines:
        key, _, value = line.partition(":")
        if key in {"PPid", "VmRSS"}:
            values[key] = value.strip()
    if "PPid" not in values:
        return None
    return int(values["PPid"]), int(values.get("VmRSS", "0 kB").split()[0])


def sample(root: int, exclude: int | None = None) -> tuple[int, int]:
    """Collect descendant RSS without including an unrelated process by PID."""
    try:
        pids = [int(pid) for pid in os.listdir("/proc") if pid.isdecimal()]
    except OSError:
        return 0, 0
    children: dict[int, list[int]] = {}
    rss: dict[int, int] = {}
    for pid in pids:
        observed = process_status(pid)
        if observed is None:
            continue
        parent, resident = observed
        children.setdefault(parent, []).append(pid)
        rss[pid] = resident

    total = 0
    count = 0
    seen: set[int] = set()
    pending = [root]
    while pending:
        pid = pending.pop()
        if pid in seen:
            continue
        seen.add(pid)
        if pid in rss and pid != exclude:
            total += rss[pid]
            count += 1
        pending.extend(children.get(pid, []))
    return total, count


def main() -> int:
    """Keep sampling until the parent creates its explicit stop marker."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--pid", type=int, required=True)
    parser.add_argument("--stop", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--interval", type=float, default=0.25)
    args = parser.parse_args()
    if args.pid < 1 or not 0.05 <= args.interval <= 5:
        parser.error("invalid root PID or sampling interval")

    measurements = 0
    peak_rss = 0
    peak_processes = 0
    peak_unix_seconds = 0.0
    trace = args.output.with_suffix('.jsonl').open('w')
    while True:
        resident, processes = sample(args.pid, exclude=os.getpid())
        observed_at = time.time()
        trace.write(json.dumps({'unix_seconds': observed_at, 'rss_kib': resident, 'processes': processes}) + '\n')
        trace.flush()
        measurements += 1
        if resident > peak_rss:
            peak_rss = resident
            peak_processes = processes
            peak_unix_seconds = observed_at
        if args.stop.exists() or process_status(args.pid) is None:
            break
        time.sleep(args.interval)

    trace.close()

    summary = {
        "sampler": "linux-proc-summed-rss-v1",
        "root_pid": args.pid,
        "interval_seconds": args.interval,
        "samples": measurements,
        "peak_total_rss_kib": peak_rss,
        "processes_at_peak": peak_processes,
        "peak_unix_seconds": peak_unix_seconds,
        "shared_pages_may_be_double_counted": True,
    }
    temporary = args.output.with_suffix(".tmp")
    temporary.write_text(json.dumps(summary, sort_keys=True) + "\n")
    temporary.replace(args.output)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
