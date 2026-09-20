#!/usr/bin/env bash
set -euo pipefail

candidate="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)/stashd-plugin-bwrap.json"
baseline=${1:?usage: $0 /path/to/docker-default-seccomp.json}

python3 - "$baseline" "$candidate" <<'PY'
import json
import sys
baseline_path, candidate_path = sys.argv[1:]
baseline = json.load(open(baseline_path, encoding='utf-8'))
candidate = json.load(open(candidate_path, encoding='utf-8'))

deltas = [
    {'names': ['clone'], 'action': 'SCMP_ACT_ALLOW', 'args': [{'index': 1, 'value': 2080505856, 'op': 'SCMP_CMP_MASKED_EQ'}], 'comment': 'Stashd bubblewrap namespace clone flags on x86_64', 'excludes': {'caps': ['CAP_SYS_ADMIN'], 'arches': ['s390', 's390x']}},
    {'names': ['mount'], 'action': 'SCMP_ACT_ALLOW', 'args': [{'index': 3, 'value': 573440, 'op': 'SCMP_CMP_EQ'}], 'comment': 'Stashd bubblewrap root MS_REC|MS_SLAVE mount', 'excludes': {'caps': ['CAP_SYS_ADMIN']}},
    {'names': ['mount'], 'action': 'SCMP_ACT_ALLOW', 'args': [{'index': 3, 'value': 6, 'op': 'SCMP_CMP_EQ'}], 'comment': 'Stashd bubblewrap tmpfs mount', 'excludes': {'caps': ['CAP_SYS_ADMIN']}},
    {'names': ['mount'], 'action': 'SCMP_ACT_ALLOW', 'args': [{'index': 3, 'value': 3236810752, 'op': 'SCMP_CMP_EQ'}], 'comment': 'Stashd bubblewrap recursive bind mount', 'excludes': {'caps': ['CAP_SYS_ADMIN']}},
    {'names': ['mount'], 'action': 'SCMP_ACT_ALLOW', 'args': [{'index': 3, 'value': 2134055, 'op': 'SCMP_CMP_EQ'}], 'comment': 'Stashd bubblewrap read-only bind remount', 'excludes': {'caps': ['CAP_SYS_ADMIN']}},
    {'names': ['mount'], 'action': 'SCMP_ACT_ALLOW', 'args': [{'index': 3, 'value': 10, 'op': 'SCMP_CMP_EQ'}], 'comment': 'Stashd bubblewrap devpts mount', 'excludes': {'caps': ['CAP_SYS_ADMIN']}},
    {'names': ['mount'], 'action': 'SCMP_ACT_ALLOW', 'args': [{'index': 3, 'value': 311296, 'op': 'SCMP_CMP_EQ'}], 'comment': 'Stashd bubblewrap private root mount', 'excludes': {'caps': ['CAP_SYS_ADMIN']}},
    {'names': ['umount2'], 'action': 'SCMP_ACT_ALLOW', 'args': [{'index': 1, 'value': 2, 'op': 'SCMP_CMP_EQ'}], 'comment': 'Stashd bubblewrap detached unmount', 'excludes': {'caps': ['CAP_SYS_ADMIN']}},
    {'names': ['pivot_root'], 'action': 'SCMP_ACT_ALLOW', 'comment': 'Stashd bubblewrap root pivot', 'excludes': {'caps': ['CAP_SYS_ADMIN']}},
    {'names': ['unshare'], 'action': 'SCMP_ACT_ALLOW', 'args': [{'index': 0, 'value': 268435456, 'op': 'SCMP_CMP_EQ'}], 'comment': 'Stashd bubblewrap user namespace unshare', 'excludes': {'caps': ['CAP_SYS_ADMIN']}},
]

for expected in deltas:
    if expected in candidate['syscalls']:
        candidate['syscalls'].remove(expected)
    else:
        raise SystemExit(f"Stashd seccomp delta is missing or changed: {expected['comment']}")

if candidate != baseline:
    raise SystemExit('Stashd seccomp profile differs from the pinned Docker baseline beyond the documented clone delta')

print('Stashd seccomp profile matches the supplied Docker baseline plus the documented bubblewrap delta.')
PY
