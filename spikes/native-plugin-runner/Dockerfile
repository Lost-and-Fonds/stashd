FROM php:8.5-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends bubblewrap ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --uid 1000 --shell /bin/sh plugin

WORKDIR /m1
COPY . /m1
RUN mkdir -p /m1/jobs /outer-vault /outer-app /outer-data \
    && printf 'outer-app-secret\n' > /outer-app/secret.txt \
    && printf 'outer-db-password=not-for-plugins\n' > /outer-data/database.env \
    && chown -R plugin:plugin /m1 /outer-vault /outer-app /outer-data

USER plugin
ENTRYPOINT ["/m1/run-tests.sh"]
