FROM php:8.5-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends bubblewrap ca-certificates tar \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --uid 1000 --shell /bin/sh plugin

COPY packages/native-plugin-runtime /src/native-plugin-runtime
COPY vendor/stashd/plugin-sdk /src/sdk
RUN mkdir -p /vault /outer-app /outer-data \
    && chown -R plugin:plugin /src /vault /outer-app /outer-data

USER plugin
ENTRYPOINT ["/src/native-plugin-runtime/tests/run-container.sh"]
