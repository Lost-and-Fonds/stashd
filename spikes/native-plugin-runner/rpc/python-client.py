#!/usr/bin/env python3
import json
import os
import struct
import subprocess
import sys

MAX_FRAME = 65_536


def write_frame(stream, message):
    payload = json.dumps(message, separators=(",", ":")).encode()
    if len(payload) > MAX_FRAME:
        raise RuntimeError("frame exceeds maximum size")
    stream.write(struct.pack(">I", len(payload)) + payload)
    stream.flush()


def read_frame(stream):
    header = stream.read(4)
    if not header:
        return None
    if len(header) != 4:
        raise RuntimeError("unexpected EOF in header")
    length = struct.unpack(">I", header)[0]
    if length > MAX_FRAME:
        raise RuntimeError("frame exceeds maximum size")
    payload = stream.read(length)
    if len(payload) != length:
        raise RuntimeError("unexpected EOF in payload")
    return json.loads(payload)


process = subprocess.Popen(
    ["php", os.path.join(os.path.dirname(__file__), "fixture-server.php")],
    stdin=subprocess.PIPE,
    stdout=subprocess.PIPE,
    stderr=subprocess.PIPE,
)
try:
    write_frame(process.stdin, {"protocol": 1, "id": "py-hello", "kind": "request", "method": "hello", "params": {"min": 1, "max": 1}})
    hello = read_frame(process.stdout)
    write_frame(process.stdin, {"protocol": 1, "kind": "notification", "method": "progress", "params": {"stage": "testing"}})
    write_frame(process.stdin, {"protocol": 1, "id": "py-ping", "kind": "request", "method": "ping", "params": {"value": "python"}})
    pong = read_frame(process.stdout)
except BrokenPipeError as error:
    process.poll()
    diagnostic = process.stderr.read().decode(errors="replace")
    raise RuntimeError(f"fixture server exited early: {diagnostic}") from error
process.stdin.close()
process.stdout.close()
process.stderr.close()
exit_code = process.wait()
if hello.get("id") != "py-hello" or pong.get("result", {}).get("pong") != "python" or exit_code != 0:
    raise RuntimeError("Python fixture client protocol check failed")
print("M2 non-PHP fixture client (Python): PASS")
