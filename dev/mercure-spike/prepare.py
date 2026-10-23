#!/usr/bin/env python3
"""Prepare disposable, ignored credentials; never overwrite an existing run."""
import json
import os
from pathlib import Path
import secrets

root = Path(__file__).resolve().parents[2]
local = root / 'tmp/mercure-spike'
os.umask(0o077)
local.mkdir(parents=True, exist_ok=True)
config = local / 'credentials.json'
if config.exists() or (root / 'public/mercure-spike.php').exists():
    raise SystemExit('Existing credentials found; tear down the previous run first.')
values = {
    'publisher': secrets.token_urlsafe(48),
    'subscriber': secrets.token_urlsafe(48),
    'password': secrets.token_urlsafe(32),
    'topic': 'https://core.test/spike/synthetic',
    'public_url': 'https://core.test/.well-known/mercure',
    'hub_url': 'http://lerd-stashd-mercure-spike/.well-known/mercure',
}
config.write_text(json.dumps(values))
service = {
    'name': 'stashd-mercure-spike',
    'image': 'localhost/stashd-mercure-spike:local',
    'data_dir': '/data',
    'environment': {
        'MERCURE_PUBLISHER_JWT_KEY': values['publisher'],
        'MERCURE_SUBSCRIBER_JWT_KEY': values['subscriber'],
    },
}
(local / 'service.json').write_text(json.dumps(service))
(root / 'public/mercure-spike.php').write_text("<?php require __DIR__ . '/../dev/mercure-spike/browser.php';\n")
print('Prepared tmp/mercure-spike/service.json and public/mercure-spike.php (ignored).')
