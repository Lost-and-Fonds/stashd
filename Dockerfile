# syntax=docker/dockerfile:1
FROM docker.io/composer:2 AS composer
FROM docker.io/node:22-bookworm-slim AS node

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
ARG TARGETARCH=amd64

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip gosu supervisor libpq-dev libicu-dev curl bubblewrap gnupg \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions pdo_pgsql sockets intl \
    && php -m | grep -i '^uri$' >/dev/null \
    && php -r 'exit(extension_loaded("uri") ? 0 : 1);'

RUN set -eux; \
    case "${TARGETARCH}" in \
        amd64) umoci_arch=amd64; umoci_sha256=b51c267ec394499e42c6fde47f240b7b7dba57ea49df0b5acd304378b82a3b71 ;; \
        arm64) umoci_arch=arm64; umoci_sha256=5cfd17f2e7a4bcf9ed67ea1b955ca893d200349b9ce6a3d3707dba415f458a1f ;; \
        *) echo "unsupported umoci architecture: ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    temporary="$(mktemp -d)"; \
    trap 'rm -rf "${temporary}"' EXIT; \
    curl -fsSL "https://raw.githubusercontent.com/opencontainers/umoci/v0.6.0/umoci.keyring" -o "${temporary}/umoci.keyring"; \
    echo 'be8d3bd71d62d0593b627fca0ef2a6a0cf854daf4f91b7364f5148c84e4d3b5c  '"${temporary}/umoci.keyring" | sha256sum -c -; \
    curl -fsSL "https://github.com/opencontainers/umoci/releases/download/v0.6.0/umoci.linux.${umoci_arch}" -o "${temporary}/umoci"; \
    echo "${umoci_sha256}  ${temporary}/umoci" | sha256sum -c -; \
    curl -fsSL "https://github.com/opencontainers/umoci/releases/download/v0.6.0/umoci.linux.${umoci_arch}.asc" -o "${temporary}/umoci.asc"; \
    export GNUPGHOME="${temporary}/gnupg"; mkdir -m 700 "${GNUPGHOME}"; \
    gpg --batch --import "${temporary}/umoci.keyring"; \
    gpg --batch --status-fd=1 --verify "${temporary}/umoci.asc" "${temporary}/umoci" >"${temporary}/signature.txt" 2>&1; \
    grep -F 'VALIDSIG B64E4955B29FA3D463F2A9062897FAD2B7E9446F' "${temporary}/signature.txt"; \
    install -D -m 0555 "${temporary}/umoci" /usr/local/libexec/stashd/umoci; \
    /usr/local/libexec/stashd/umoci --version | grep -F '0.6.0'; \
    apt-get purge -y --auto-remove gnupg >/dev/null; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

RUN groupadd -g "${PGID}" stashd \
    && useradd -u "${PUID}" -g stashd -d /var/www/html -s /usr/sbin/nologin stashd

COPY docker/supervisord.conf.template /etc/supervisor/stashd.conf.template
COPY docker/entrypoint.sh /usr/local/bin/stashd-entrypoint
RUN chmod +x /usr/local/bin/stashd-entrypoint

ENV STASHD_HTTP_PORT=8474 \
    STASHD_DATA_PATH=/data \
    STASHD_MEDIA_PATH=/media \
    STASHD_PUBLIC_URL=http://localhost:8474

EXPOSE 8474

ENTRYPOINT ["stashd-entrypoint"]
CMD ["all"]

FROM base AS dev

COPY --from=node /usr/local /usr/local

RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential pkg-config \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions xdebug pcov

COPY docker/php-dev.ini /usr/local/etc/php/conf.d/zz-stashd-dev.ini
ENV XDEBUG_MODE=off

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
