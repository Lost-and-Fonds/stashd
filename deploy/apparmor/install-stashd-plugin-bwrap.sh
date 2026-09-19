#!/usr/bin/env bash
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROFILE_NAME=stashd-plugin-bwrap
PROFILE_SOURCE="$ROOT/$PROFILE_NAME"
PROFILE_TARGET="/etc/apparmor.d/$PROFILE_NAME"
require_active=0

if [ "${1:-}" = '--require' ]; then
    require_active=1
elif [ "${1:-}" != '' ]; then
    echo "usage: $0 [--require]" >&2
    exit 2
fi

if ! command -v aa-status >/dev/null 2>&1 || ! command -v apparmor_parser >/dev/null 2>&1; then
    if [ "$require_active" -eq 1 ]; then
        echo 'AppArmor tools are required but aa-status/apparmor_parser are unavailable.' >&2
        exit 1
    fi

    echo 'AppArmor tools are unavailable; Stashd can continue with Docker default security.'
    exit 0
fi

if ! aa-status --enabled >/dev/null 2>&1; then
    if [ "$require_active" -eq 1 ]; then
        echo 'AppArmor is not active; refusing to claim an authoritative AppArmor proof.' >&2
        exit 1
    fi

    echo 'AppArmor is not active; Stashd can continue with Docker default security.'
    exit 0
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "AppArmor is active; root is required to install $PROFILE_TARGET. Re-run with sudo." >&2
    exit 1
fi

if [ ! -r "$PROFILE_SOURCE" ]; then
    echo "Profile source is missing: $PROFILE_SOURCE" >&2
    exit 1
fi

install -D -m 0644 "$PROFILE_SOURCE" "$PROFILE_TARGET"
apparmor_parser -r -W "$PROFILE_TARGET"

if ! aa-status --profiled 2>/dev/null | awk '{print $1}' | grep -Fxq "$PROFILE_NAME"; then
    echo "AppArmor did not report the expected loaded profile: $PROFILE_NAME" >&2
    exit 1
fi

sysctl_value='unavailable'
if [ -r /proc/sys/kernel/apparmor_restrict_unprivileged_userns ]; then
    sysctl_value=$(cat /proc/sys/kernel/apparmor_restrict_unprivileged_userns)
fi

echo "Loaded AppArmor profile: $PROFILE_NAME"
echo "kernel.apparmor_restrict_unprivileged_userns: $sysctl_value"
