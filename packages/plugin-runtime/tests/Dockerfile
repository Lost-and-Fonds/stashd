FROM php:8.5-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends bubblewrap ca-certificates composer tar \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --uid 1000 --shell /bin/sh plugin

COPY packages/plugin-runtime /src/plugin-runtime
COPY vendor/stashd/plugin-sdk /src/sdk
RUN composer install --working-dir=/src/plugin-runtime --no-dev --no-interaction --prefer-dist
RUN mkdir -p /vault /outer-app /outer-data \
    && chown -R plugin:plugin /src /vault /outer-app /outer-data

USER plugin
ENTRYPOINT ["/src/plugin-runtime/tests/run-container.sh"]
