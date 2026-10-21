#!/usr/bin/env python3
"""Reproduce hub shutdown independently of Lerd's proxy and service lifecycle."""
from contextlib import ExitStack
import json
import os
from pathlib import Path
import subprocess
import urllib.request

root = Path(__file__).resolve().parents[2]
os.chdir(root)
config = json.loads((root / 'tmp/mercure-spike/credentials.json').read_text())
name = 'stashd-mercure-shutdown-probe'


def run(*args, **kwargs):
    """Run local investigation tooling, keeping credential values out of output."""
    return subprocess.check_output(args, text=True, **kwargs).strip()


environment = dict(os.environ, MERCURE_PUBLISHER_JWT_KEY=config['publisher'],
                   MERCURE_SUBSCRIBER_JWT_KEY=config['subscriber'])
# Refuse a name collision; cleanup is limited to the container created here.
existing = subprocess.run(['podman', 'container', 'exists', name], check=False)
if existing.returncode == 0:
    raise SystemExit(f'{name} already exists; inspect it before continuing.')
run('podman', 'run', '-d', '--name', name, '-p', '127.0.0.1::80',
    '--env', 'MERCURE_PUBLISHER_JWT_KEY', '--env', 'MERCURE_SUBSCRIBER_JWT_KEY',
    'localhost/stashd-mercure-spike:local', env=environment)
try:
    address = run('podman', 'port', name, '80/tcp')
    token = run('lerd', 'php', 'dev/mercure-spike/fixture.php', 'token', 'subscribe')
    request = urllib.request.Request(
        'http://' + address + '/.well-known/mercure?topic=' + config['topic'],
        headers={'Authorization': 'Bearer ' + token})
    with ExitStack() as stack:
        for _ in range(2):
            response = stack.enter_context(urllib.request.urlopen(request, timeout=10))
            assert response.status == 200
            assert response.headers.get_content_type() == 'text/event-stream'
        run('podman', 'stop', '--time', '10', name)
    logs = subprocess.run(['podman', 'logs', name], capture_output=True, text=True, check=True)
    text = logs.stdout + logs.stderr
    report = {
        'proxy': 'direct random loopback port, no nginx or Lerd-managed hub',
        'exit_code': run('podman', 'inspect', '--format', '{{.State.ExitCode}}', name),
        'grace_timeout': 'server graceful shutdown 5s timeout' in text,
        'closed_transport_error': 'read/write on closed Transport' in text,
    }
    (root / 'tmp/mercure-spike/shutdown-evidence.json').write_text(json.dumps(report, indent=2))
    print(json.dumps(report, indent=2))
finally:
    subprocess.run(['podman', 'rm', '-f', '--volumes', name], check=True, stdout=subprocess.DEVNULL)
