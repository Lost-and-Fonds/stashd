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

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip gosu supervisor libpq-dev libicu-dev curl bubblewrap \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions pdo_pgsql sockets intl \
    && php -m | grep -i '^uri$' >/dev/null \
    && php -r 'exit(extension_loaded("uri") ? 0 : 1);'

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
