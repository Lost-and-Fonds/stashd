# syntax=docker/dockerfile:1
FROM docker.io/composer:2 AS composer

# Build the front-end assets (Vite + Tailwind + Alpine) into public/build/.
# The vite-plugin-tempest plugin normally shells out to `php tempest vite:config`
# for its settings; we feed it inline via TEMPEST_PLUGIN_CONFIGURATION_OVERRIDE
# so this stage stays pure Node (no PHP needed).
FROM docker.io/node:22-bookworm-slim AS node

FROM docker.io/rust:1.97-bookworm AS rust

RUN rustup target add wasm32-wasip2 \
    && rustup component add rustfmt clippy

# The plugin host and bundled Components are part of the normal application
# runtime.  Build them once here so the PHP lifecycle uses the same private
# host process as the development spike; PHP never needs to compile or launch
# provider code itself.
FROM rust AS plugin-runtime
WORKDIR /plugin-build
ENV CARGO_BUILD_JOBS=1
ARG TARGETARCH
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl xz-utils \
    && rm -rf /var/lib/apt/lists/* \
    && case "${TARGETARCH}" in \
        amd64) ffmpegAsset="ffmpeg-N-126239-g88ae625e69-linux64-gpl.tar.xz"; ffmpegSha="b2ad9015c296a61c1f6127c4aa3ce8614a9bd8d7519987b6e0d151edaa7f39fb" ;; \
        arm64) ffmpegAsset="ffmpeg-N-126239-g88ae625e69-linuxarm64-gpl.tar.xz"; ffmpegSha="59f9c4258284fa750b025939b210aa51a9cb8b6411d4a417f332facc2d0d2df2" ;; \
        *) echo "Unsupported architecture for FFmpeg: ${TARGETARCH}" >&2; exit 1 ;; \
    esac \
    && curl -fL "https://github.com/BtbN/FFmpeg-Builds/releases/download/autobuild-2026-08-21-13-40/${ffmpegAsset}" -o /tmp/ffmpeg.tar.xz \
    && printf '%s  %s\n' "${ffmpegSha}" /tmp/ffmpeg.tar.xz | sha256sum -c - \
    && mkdir -p /tmp/ffmpeg \
    && tar -xJf /tmp/ffmpeg.tar.xz --strip-components=1 -C /tmp/ffmpeg \
    && mkdir -p /plugin-output/podcast/helpers \
    && cp /tmp/ffmpeg/bin/ffmpeg /plugin-output/podcast/helpers/ffmpeg \
    && chmod a+rx /plugin-output/podcast/helpers/ffmpeg \
    && rm -rf /tmp/ffmpeg /tmp/ffmpeg.tar.xz
COPY Cargo.toml Cargo.lock ./
COPY plugin-api ./plugin-api
COPY plugin-host ./plugin-host
COPY plugins/example ./plugins/example
COPY plugins/youtube ./plugins/youtube
COPY plugins/podcast ./plugins/podcast
COPY plugins/jellyfin ./plugins/jellyfin
COPY plugins/plex ./plugins/plex
RUN cargo build -p stashd-plugin-host --release \
    && cargo build -p stashd-youtube-plugin --target wasm32-wasip2 --release \
    && cargo build -p stashd-podcast-plugin --target wasm32-wasip2 --release \
    && cargo build -p stashd-jellyfin-plugin --target wasm32-wasip2 --release \
    && cargo build -p stashd-plex-plugin --target wasm32-wasip2 --release \
    && mkdir -p /plugin-output \
    && target/release/stashd-plugin-host build-component \
        target/wasm32-wasip2/release/stashd_youtube_plugin.wasm \
        /plugin-output/youtube.wasm \
    && target/release/stashd-plugin-host build-component \
        target/wasm32-wasip2/release/stashd_podcast_plugin.wasm \
        /plugin-output/podcast.wasm \
    && target/release/stashd-plugin-host build-component \
        target/wasm32-wasip2/release/stashd_jellyfin_plugin.wasm \
        /plugin-output/jellyfin.wasm \
    && target/release/stashd-plugin-host build-component \
        target/wasm32-wasip2/release/stashd_plex_plugin.wasm \
        /plugin-output/plex.wasm

FROM node AS assets
WORKDIR /app
ENV TEMPEST_PLUGIN_CONFIGURATION_OVERRIDE='{"build_directory":"build","bridge_file_name":"vite-tempest","manifest":"manifest.json","entrypoints":["src/main.entrypoint.ts"]}'
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.ts ./
COPY src ./src
COPY app ./app
RUN npm run build

FROM docker.io/dunglas/frankenphp:1-php8.5-bookworm AS base

ARG PUID=1000
ARG PGID=1000
ARG TARGETARCH

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        gosu \
        supervisor \
        sqlite3 \
        libsqlite3-dev \
        libpq-dev \
        libicu-dev \
        curl \
    && rm -rf /var/lib/apt/lists/*

# install-php-extensions (bundled in the FrankenPHP image) compiles for ZTS
# automatically -- FrankenPHP's threaded worker model requires it, unlike the
# non-ZTS php:8.5-cli-bookworm build this replaces.
RUN install-php-extensions pdo_sqlite pdo_pgsql sockets intl \
    && php -m | grep -i '^uri$' >/dev/null \
    && php -r 'exit(extension_loaded("uri") ? 0 : 1);'

# yt-dlp's plain "yt-dlp" release asset is an amd64-only PyInstaller build --
# arm64 needs the dedicated "yt-dlp_linux_aarch64" asset, or it silently
# installs a binary that can't execute on that architecture.
RUN case "${TARGETARCH}" in \
        amd64) ytdlpAsset="yt-dlp" ;; \
        arm64) ytdlpAsset="yt-dlp_linux_aarch64" ;; \
        *) echo "Unsupported architecture for yt-dlp: ${TARGETARCH}" >&2; exit 1 ;; \
    esac \
    && curl -L "https://github.com/yt-dlp/yt-dlp/releases/latest/download/${ytdlpAsset}" -o /usr/local/bin/yt-dlp \
    && chmod a+rx /usr/local/bin/yt-dlp

# yt-dlp's YouTube extractor needs an external JS runtime to fully solve
# player signature/challenge JS; without one it runs in a degraded mode
# ("YouTube extraction without a JS runtime has been deprecated") that can
# intermittently misreport a playable video as unavailable. Deno is yt-dlp's
# own zero-config default runtime ("Only deno is enabled by default") and
# ships as a single static binary, so no extra yt-dlp flags are needed once
# it's on PATH.
RUN case "${TARGETARCH}" in \
        amd64) denoTarget="x86_64-unknown-linux-gnu" ;; \
        arm64) denoTarget="aarch64-unknown-linux-gnu" ;; \
        *) echo "Unsupported architecture for deno: ${TARGETARCH}" >&2; exit 1 ;; \
    esac \
    && curl -L "https://github.com/denoland/deno/releases/latest/download/deno-${denoTarget}.zip" -o /tmp/deno.zip \
    && unzip -o /tmp/deno.zip -d /usr/local/bin \
    && rm /tmp/deno.zip \
    && chmod a+rx /usr/local/bin/deno

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --from=plugin-runtime /plugin-build/target/release/stashd-plugin-host /usr/local/bin/stashd-plugin-host
COPY --from=plugin-runtime /plugin-output /usr/local/share/stashd/plugins

WORKDIR /var/www/html

RUN groupadd -g "${PGID}" stashd \
    && useradd -u "${PUID}" -g stashd -d /var/www/html -s /usr/sbin/nologin stashd

COPY docker/supervisord.conf.template /etc/supervisor/stashd.conf.template
COPY docker/entrypoint.sh /usr/local/bin/stashd-entrypoint
RUN chmod +x /usr/local/bin/stashd-entrypoint

ENV STASHD_HTTP_PORT=8474 \
    STASHD_DATA_PATH=/data \
    STASHD_MEDIA_PATH=/media \
    STASHD_PUBLIC_URL=http://localhost:8474 \
    STASHD_PLUGIN_HOST_SOCKET=/tmp/stashd-plugin-host.sock \
    STASHD_PLUGIN_COMPONENT=/usr/local/share/stashd/plugins/youtube.wasm \
    STASHD_BROADCAST_PLUGIN_COMPONENT=/usr/local/share/stashd/plugins/podcast.wasm \
    STASHD_BROADCAST_PLUGIN_COMPONENT_JELLYFIN=/usr/local/share/stashd/plugins/jellyfin.wasm \
    STASHD_BROADCAST_PLUGIN_COMPONENT_PLEX=/usr/local/share/stashd/plugins/plex.wasm

EXPOSE 8474

ENTRYPOINT ["stashd-entrypoint"]
CMD ["all"]

FROM base AS dev

# This target intentionally does not COPY application source or install
# composer/npm dependencies: lerd's custom-container dev setup bind-mounts
# the live host checkout over --workdir at runtime instead (see
# docker/entrypoint.sh), so source edits take effect on a plain container
# restart rather than a full image rebuild. That bind-mounted checkout is
# expected to already have vendor/ and public/build/ in place (via
# `composer install` and `npm run build` run against the host checkout, e.g.
# through lerd's exec tooling) -- the same prerequisites any non-Dockerized
# local PHP setup would need.
COPY --from=node /usr/local /usr/local
COPY --from=rust /usr/local/cargo /usr/local/cargo
COPY --from=rust /usr/local/rustup /usr/local/rustup

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        build-essential \
        pkg-config \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions xdebug pcov

RUN for binary in cargo cargo-clippy cargo-fmt clippy-driver rustc rustdoc rustfmt rustup; do \
        ln -s "/usr/local/cargo/bin/${binary}" "/usr/local/bin/${binary}"; \
    done

COPY docker/php-dev.ini /usr/local/etc/php/conf.d/zz-stashd-dev.ini

ENV XDEBUG_MODE=off
ENV PATH="/usr/local/cargo/bin:${PATH}" \
    CARGO_HOME=/usr/local/cargo \
    RUSTUP_HOME=/usr/local/rustup \
    STASHD_BROADCAST_PLUGIN_COMPONENT_JELLYFIN=/usr/local/share/stashd/plugins/jellyfin.wasm \
    STASHD_BROADCAST_PLUGIN_COMPONENT_PLEX=/usr/local/share/stashd/plugins/plex.wasm

FROM base AS prod

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .
COPY --from=assets /app/public/build ./public/build
RUN git config --global --add safe.directory /var/www/html \
    && composer dump-autoload --optimize \
    && php vendor/bin/tempest discovery:generate --no-interaction \
    && rm -rf .tempest

RUN chown -R stashd:stashd /var/www/html
