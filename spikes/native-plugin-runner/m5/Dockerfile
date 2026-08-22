FROM php:8.5-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends bubblewrap ca-certificates python3 \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --uid 1000 --shell /bin/sh plugin

WORKDIR /m5
COPY . /m5
RUN mkdir -p /vault /outer-app /outer-data \
    && chown -R plugin:plugin /m5 /vault /outer-app /outer-data

USER plugin
ENTRYPOINT ["/m5/m5/run-tests.sh"]
