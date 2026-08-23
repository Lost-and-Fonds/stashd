FROM composer:2 AS plugin-runtime-dependencies

COPY packages/plugin-runtime/composer.json /src/plugin-runtime/composer.json
RUN composer install --working-dir=/src/plugin-runtime --no-dev --no-interaction --prefer-dist --ignore-platform-req=php --ignore-platform-req=ext-intl

FROM php:8.5-cli-bookworm

ARG TARGETARCH=amd64

RUN apt-get update \
    && apt-get install -y --no-install-recommends bubblewrap ca-certificates curl gnupg tar \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --uid 1000 --shell /bin/sh plugin

RUN set -eux; \
    case "${TARGETARCH}" in \
        amd64) umoci_arch=amd64; umoci_sha256=b51c267ec394499e42c6fde47f240b7b7dba57ea49df0b5acd304378b82a3b71 ;; \
        arm64) umoci_arch=arm64; umoci_sha256=5cfd17f2e7a4bcf9ed67ea1b955ca893d200349b9ce6a3d3707dba415f458a1f ;; \
        *) exit 1 ;; \
    esac; \
    temporary="$(mktemp -d)"; trap 'rm -rf "${temporary}"' EXIT; \
    curl -fsSL https://raw.githubusercontent.com/opencontainers/umoci/v0.6.0/umoci.keyring -o "${temporary}/keyring"; \
    echo 'be8d3bd71d62d0593b627fca0ef2a6a0cf854daf4f91b7364f5148c84e4d3b5c  '"${temporary}/keyring" | sha256sum -c -; \
    curl -fsSL "https://github.com/opencontainers/umoci/releases/download/v0.6.0/umoci.linux.${umoci_arch}" -o "${temporary}/umoci"; \
    echo "${umoci_sha256}  ${temporary}/umoci" | sha256sum -c -; \
    curl -fsSL "https://github.com/opencontainers/umoci/releases/download/v0.6.0/umoci.linux.${umoci_arch}.asc" -o "${temporary}/umoci.asc"; \
    export GNUPGHOME="${temporary}/gnupg"; mkdir -m 700 "${GNUPGHOME}"; \
    gpg --batch --import "${temporary}/keyring"; \
    gpg --batch --status-fd=1 --verify "${temporary}/umoci.asc" "${temporary}/umoci" >"${temporary}/signature.txt" 2>&1; \
    grep -F 'VALIDSIG B64E4955B29FA3D463F2A9062897FAD2B7E9446F' "${temporary}/signature.txt"; \
    install -D -m 0555 "${temporary}/umoci" /usr/local/libexec/stashd/umoci

COPY packages/plugin-runtime /src/plugin-runtime
COPY vendor/stashd/plugin-sdk /src/sdk
COPY --from=plugin-runtime-dependencies /src/plugin-runtime/vendor /src/plugin-runtime/vendor
COPY --from=plugin-runtime-dependencies /usr/bin/composer /usr/bin/composer
RUN mkdir -p /vault /outer-app /outer-data \
    && chown -R plugin:plugin /src /vault /outer-app /outer-data

USER plugin
ENTRYPOINT ["/src/plugin-runtime/tests/run-container.sh"]
