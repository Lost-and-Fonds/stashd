FROM php:8.5-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends bubblewrap ca-certificates tar \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --uid 1000 --shell /bin/sh plugin

WORKDIR /m6
COPY . /m6
RUN mkdir -p /vault /outer-app /outer-data \
    && chown -R plugin:plugin /m6 /vault /outer-app /outer-data

USER plugin
ENTRYPOINT ["/m6/m6/run-tests.sh"]
