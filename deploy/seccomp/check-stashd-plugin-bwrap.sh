#!/usr/bin/env bash
set -euo pipefail

candidate="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)/stashd-plugin-bwrap.json"
baseline=${1:?usage: $0 /path/to/docker-default-seccomp.json}

python3 - "$baseline" "$candidate" <<'PY'
import json
import sys
from copy import deepcopy

baseline_path, candidate_path = sys.argv[1:]
baseline = json.load(open(baseline_path, encoding='utf-8'))
candidate = json.load(open(candidate_path, encoding='utf-8'))

for index, rule in enumerate(baseline['syscalls']):
    if rule.get('names') == ['clone'] and rule.get('excludes', {}).get('arches') == ['s390', 's390x']:
        expected = deepcopy(rule)
        expected['args'][0]['value'] = 2080505856
        baseline['syscalls'][index] = expected
        break
else:
    raise SystemExit('Docker baseline does not contain the expected non-s390 clone rule')

if candidate != baseline:
    raise SystemExit('Stashd seccomp profile differs from the pinned Docker baseline beyond the documented clone delta')

print('Stashd seccomp profile matches the supplied Docker baseline plus its one clone argument delta.')
PY
