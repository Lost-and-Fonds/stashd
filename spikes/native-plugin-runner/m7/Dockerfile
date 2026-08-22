FROM php:8.5-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends bubblewrap ca-certificates tar \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --uid 1000 --shell /bin/sh plugin

WORKDIR /m7
COPY . /m7
RUN mkdir -p /vault /outer-app /outer-data \
    && chown -R plugin:plugin /m7 /vault /outer-app /outer-data

USER plugin
ENTRYPOINT ["/m7/m7/run-tests.sh"]
