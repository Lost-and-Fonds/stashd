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

expected = {
    'names': ['clone'],
    'action': 'SCMP_ACT_ALLOW',
    'args': [{'index': 1, 'value': 2080505856, 'op': 'SCMP_CMP_MASKED_EQ'}],
    'comment': 'Stashd bubblewrap namespace clone flags on x86_64',
    'excludes': {'caps': ['CAP_SYS_ADMIN'], 'arches': ['s390', 's390x']},
}

if expected in candidate['syscalls']:
    candidate['syscalls'].remove(expected)
else:
    raise SystemExit('Stashd bubblewrap clone rule is missing or changed')

if candidate != baseline:
    raise SystemExit('Stashd seccomp profile differs from the pinned Docker baseline beyond the documented clone delta')

print('Stashd seccomp profile matches the supplied Docker baseline plus its one clone argument delta.')
PY
