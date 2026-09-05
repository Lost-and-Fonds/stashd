#!/bin/sh
set -eu

# /var/www/html is the image's own WORKDIR, baked at build time. Under lerd's
# custom-container dev setup, the live host checkout is bind-mounted with
# --workdir pointed at it instead, so the inherited cwd here is the real
# source tree, not /var/www/html -- letting dev code changes take effect on a
# plain container restart instead of a full image rebuild. In prod (no
# --workdir override), this naturally resolves to /var/www/html, unchanged.
APP_DIR="$(pwd)"
DATA_DIR="${STASHD_DATA_PATH:-/data}"
MEDIA_DIR="${STASHD_MEDIA_PATH:-/media}"
# Lerd bind-mounts the checkout but does not inject .env values as container
# environment. Let the local .env override the image default for its persistent
# project-local media directory; production keeps the explicit /media mount.
if [ "$APP_DIR" != "/var/www/html" ] && [ -f "$APP_DIR/.env" ]; then
    configured_media_dir="$(sed -n 's/^STASHD_MEDIA_PATH=//p' "$APP_DIR/.env" | tail -n 1)"
    if [ -n "$configured_media_dir" ]; then
        MEDIA_DIR="$configured_media_dir"
    fi
fi
PUID="${PUID:-1000}"
PGID="${PGID:-1000}"

log() {
    printf 'stashd: %s\n' "$*"
}

run_app() {
    # Dropping to an unprivileged uid:gid only makes sense against the image's
    # own baked copy: under rootless Podman, "root" inside the container is
    # the user-namespace-mapped equivalent of the host user that started it
    # (the same mapping lerd's own exec sessions rely on), but a non-root
    # in-container uid maps to an unrelated host subuid -- which can't write
    # to the live bind-mounted checkout even when it numerically matches the
    # host owner's uid. Dev's live mount is already the developer's own
    # machine/source, so there's nothing to additionally sandbox by dropping
    # privileges there.
    #
    # gosu takes the raw numeric ids directly rather than a "stashd" username
    # resolved via /etc/passwd -- deliberately: a prior version remapped the
    # image's baked stashd user (uid 1000) to PUID via `usermod -u`, which
    # shadow-utils implements by recursively chowning every file already
    # owned by uid 1000 under that user's home directory (/var/www/html, the
    # full app + vendor tree). Any PUID other than the image default paid for
    # that walk on every container start, and on slower/networked storage
    # (common for NAS bind mounts) it could take minutes before the app was
    # reachable, with nothing logged in the meantime since remap ran before
    # the first log line. /var/www/html only ever needs to be *readable* at
    # runtime -- its build-time chown to stashd:stashd already leaves it
    # world-readable -- so no uid match is needed there at all; the paths
    # that do need to be writable (DATA_DIR, MEDIA_DIR, .env) are already
    # chowned to PUID:PGID explicitly elsewhere in this script.
    if [ "$APP_DIR" = "/var/www/html" ] && [ "$(id -u)" -eq 0 ]; then
        gosu "${PUID}:${PGID}" "$@"
    else
        "$@"
    fi
}

ensure_writable() {
    for dir in "$DATA_DIR" "$MEDIA_DIR"; do
        mkdir -p "$dir"
        if [ "$(id -u)" -eq 0 ] && [ "$APP_DIR" = "/var/www/html" ]; then
            chown -R "${PUID}:${PGID}" "$dir" || true
        fi
    done

}

ensure_signing_key() {
    if [ -n "${SIGNING_KEY:-}" ]; then
        log "using operator-supplied SIGNING_KEY"
        return 0
    fi

    # The $DATA_DIR roundtrip below only matters when $APP_DIR is the image's
    # own ephemeral baked copy (prod, or a dev image that still bakes source):
    # its .env resets to the repo's committed copy on every rebuild and would
    # otherwise lose a freshly generated key. When $APP_DIR is the live
    # bind-mounted dev checkout, .env is the developer's real, persistent
    # file -- key:generate --no-override is enough, and copying another file
    # over it here would clobber their local settings.
    if [ "$APP_DIR" = "/var/www/html" ]; then
        # A symlink into the bind-mounted $DATA_DIR is deliberately avoided here:
        # some container security profiles (confirmed via AppArmor's docker-default
        # on this host) deny non-root traversal of a symlink that crosses from the
        # image's own filesystem into a bind-mounted volume, even though root can
        # follow it fine. Copying the file instead never crosses that boundary.
        persisted_env="$DATA_DIR/.env"

        if [ -f "$persisted_env" ]; then
            cp "$persisted_env" "$APP_DIR/.env" || true
            if [ "$(id -u)" -eq 0 ]; then
                chown "${PUID}:${PGID}" "$APP_DIR/.env" || true
            fi
        fi

        if [ ! -f "$APP_DIR/.env" ] && [ "$(id -u)" -eq 0 ]; then
            touch "$APP_DIR/.env"
            chown "${PUID}:${PGID}" "$APP_DIR/.env" || true
        fi

        run_app php tempest key:generate --no-override

        cp "$APP_DIR/.env" "$persisted_env"
        if [ "$(id -u)" -eq 0 ]; then
            chown "${PUID}:${PGID}" "$persisted_env" || true
        fi
    else
        run_app php tempest key:generate --no-override
    fi
}

ensure_mercure_secret() {
    if [ -n "${MERCURE_JWT_SECRET:-}" ]; then
        log "using operator-supplied MERCURE_JWT_SECRET"
        export MERCURE_JWT_SECRET
        return 0
    fi

    # Unlike SIGNING_KEY, generating this needs no Tempest command running
    # against $APP_DIR -- it's pure shell -- so $DATA_DIR/.env (already made
    # writable for PUID:PGID by ensure_writable) is the authoritative copy.
    # $APP_DIR/.env's baked ownership is a build-time uid (the Dockerfile's
    # own stashd user, created at image-build PUID, independent of the
    # runtime PUID an operator passes), and on hosts where root inside the
    # container can't actually override file permissions (no CAP_DAC_OVERRIDE
    # -- seen on some hardened NAS Docker setups), writing there fails even
    # after root's own chown of it. Mirroring into $APP_DIR/.env below is
    # therefore best-effort only: exporting the OS env var is already enough
    # for both Tempest's Dotenv (immutable/already-set precedence) and
    # Caddy's `{$MERCURE_JWT_SECRET}` placeholder.
    if [ "$APP_DIR" = "/var/www/html" ]; then
        persisted_env="$DATA_DIR/.env"

        if ! grep -q '^MERCURE_JWT_SECRET=' "$persisted_env" 2>/dev/null; then
            printf 'MERCURE_JWT_SECRET=%s\n' "$(head -c32 /dev/urandom | base64 | tr -d '\n')" >> "$persisted_env"
            if [ "$(id -u)" -eq 0 ]; then
                chown "${PUID}:${PGID}" "$persisted_env" || true
            fi
        fi

        MERCURE_JWT_SECRET="$(grep '^MERCURE_JWT_SECRET=' "$persisted_env" | tail -1 | cut -d= -f2-)"

        if ! grep -q '^MERCURE_JWT_SECRET=' "$APP_DIR/.env" 2>/dev/null; then
            printf 'MERCURE_JWT_SECRET=%s\n' "$MERCURE_JWT_SECRET" >> "$APP_DIR/.env" 2>/dev/null || true
        fi
    else
        if ! grep -q '^MERCURE_JWT_SECRET=' "$APP_DIR/.env" 2>/dev/null; then
            printf 'MERCURE_JWT_SECRET=%s\n' "$(head -c32 /dev/urandom | base64 | tr -d '\n')" >> "$APP_DIR/.env"
        fi

        MERCURE_JWT_SECRET="$(grep '^MERCURE_JWT_SECRET=' "$APP_DIR/.env" | tail -1 | cut -d= -f2-)"
    fi

    export MERCURE_JWT_SECRET
}

# Everything stashd:boot needs, without running it -- the import role wants the
# schema in place but no rows yet.
prepare_runtime_env() {
    cd "$APP_DIR"
    if command -v git >/dev/null 2>&1 && git -C "$APP_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true
    fi
    export STASHD_DATA_PATH="$DATA_DIR"
    export STASHD_MEDIA_PATH="$MEDIA_DIR"
    if [ "$APP_DIR" = "/var/www/html" ]; then
        export STASHD_PLUGIN_PACKAGE_ROOT="${DATA_DIR}/plugins"
    else
        export STASHD_PLUGIN_PACKAGE_ROOT="${STASHD_PLUGIN_PACKAGE_ROOT:-$APP_DIR/.stashd/plugin-packages}"
    fi
    export TEMPEST_INTERNAL_STORAGE="${DATA_DIR}/.tempest"
    ensure_writable
    ensure_signing_key
    ensure_mercure_secret
}

prepare_runtime() {
    prepare_runtime_env
    run_app php tempest stashd:boot
}

ensure_frontend() {
    if [ -f "$APP_DIR/public/index.html" ] || [ ! -f "$APP_DIR/package.json" ]; then
        return 0
    fi

    log "building frontend"
    run_app npx vite build --outDir public --emptyOutDir false
}

ROLE="${1:-all}"

cd "$APP_DIR"

export_runtime_env() {
    export STASHD_DATA_PATH="$DATA_DIR"
    export STASHD_MEDIA_PATH="$MEDIA_DIR"
    if [ "$APP_DIR" = "/var/www/html" ]; then
        export STASHD_PLUGIN_PACKAGE_ROOT="${DATA_DIR}/plugins"
    else
        export STASHD_PLUGIN_PACKAGE_ROOT="${STASHD_PLUGIN_PACKAGE_ROOT:-$APP_DIR/.stashd/plugin-packages}"
    fi
    export TEMPEST_INTERNAL_STORAGE="${DATA_DIR}/.tempest"
}

case "$ROLE" in
    all)
        prepare_runtime
        ensure_frontend
        # $APP_DIR is only known at runtime (see the comment near its
        # declaration above), so the per-program `directory=` lines are
        # rendered into place here rather than baked into the image.
        sed "s#__APP_DIR__#${APP_DIR}#g" /etc/supervisor/stashd.conf.template > /etc/supervisor/conf.d/stashd.conf
        log "starting supervisord"
        exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
        ;;
    serve)
        prepare_runtime_env
        ensure_frontend
        # Caddy (inside frankenphp) wants a writable config/data dir for its
        # own state; the gosu'd PUID may have no usable $HOME, so point it at
        # DATA_DIR, which is chowned to PUID:PGID by ensure_writable() (run as
        # part of the "all"/"boot" roles before this one starts in Docker).
        export XDG_CONFIG_HOME="${DATA_DIR}/.config"
        export XDG_DATA_HOME="${DATA_DIR}/.local/share"
        run_app frankenphp run --config docker/Caddyfile
        ;;
    worker)
        export_runtime_env
        # Optional second arg picks a Messenger workload (interactive or
        # background).
        run_app php tempest stashd worker ${2:+"$2"}
        ;;
    scheduler)
        export_runtime_env
        run_app php tempest stashd scheduler
        ;;
    boot)
        ensure_frontend
        prepare_runtime
        ;;
    *)
        log "unknown role: ${ROLE}" >&2
        exit 1
        ;;
esac
