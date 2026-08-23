FROM composer:2 AS plugin-runtime-dependencies

COPY packages/plugin-runtime/composer.json /src/plugin-runtime/composer.json
RUN composer install --working-dir=/src/plugin-runtime --no-dev --no-interaction --prefer-dist --ignore-platform-req=php --ignore-platform-req=ext-intl

FROM php:8.5-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends bubblewrap ca-certificates tar \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --uid 1000 --shell /bin/sh plugin

COPY packages/plugin-runtime /src/plugin-runtime
COPY vendor/stashd/plugin-sdk /src/sdk
COPY --from=plugin-runtime-dependencies /src/plugin-runtime/vendor /src/plugin-runtime/vendor
RUN mkdir -p /vault /outer-app /outer-data \
    && chown -R plugin:plugin /src /vault /outer-app /outer-data

USER plugin
ENTRYPOINT ["/src/plugin-runtime/tests/run-container.sh"]
