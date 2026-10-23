#!/usr/bin/env python3
"""Prove Lerd's ignored FrankenPHP overlay with a harmless command-line marker.

Run only in the Core checkout on classic mode, with no existing personal Tempest
framework override. This briefly restarts Core's runtime and workers twice, then
removes the temporary overlay and restores the normal runtime specification.
"""
import json
from pathlib import Path
import subprocess
import tempfile

root = Path(__file__).resolve().parents[2]
overlay = Path.home() / '.config/lerd/frameworks/tempest.yaml'
if overlay.exists():
    raise SystemExit('Existing personal Tempest override; refusing to replace it.')


def run(*args):
    """Run supported local tooling from the owning checkout and fail visibly."""
    return subprocess.check_output(args, cwd=root, text=True).strip()


if run('lerd', 'runtime') != 'Runtime: frankenphp':
    raise SystemExit('This reproducer requires Core already running classic mode.')
requested = ['frankenphp', 'php-server', '-l', ':8000', '-r', 'public', '--no-compress']
with tempfile.TemporaryDirectory(prefix='stashd-lerd-overlay-') as directory:
    definition = Path(directory) / 'tempest.json'
    definition.write_text(json.dumps({'name': 'tempest', 'public_dir': 'public',
                                      'frankenphp': {'entrypoint': requested}}))
    try:
        run('lerd', 'framework', 'add', 'tempest', '--from-file', str(definition))
        run('lerd', 'runtime', 'frankenphp', '--no-worker')
        actual = json.loads(run('podman', 'inspect', 'lerd-fp-core', '--format', '{{json .Config.Cmd}}'))
        report = {'requested_entrypoint': requested, 'actual_entrypoint': actual,
                  'overlay_applied': actual == requested}
        output = root / 'tmp/mercure-spike/lerd-overlay-evidence.json'
        output.write_text(json.dumps(report, indent=2))
        print(json.dumps(report, indent=2))
    finally:
        # This exact file did not exist before this run; never remove a base definition.
        overlay.unlink(missing_ok=True)
        run('lerd', 'runtime', 'frankenphp', '--no-worker')
