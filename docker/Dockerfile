ARG PHP_IMAGE=php:8.3-fpm-bookworm
ARG COMPOSER_IMAGE=composer:2.8
ARG DEBIAN_MIRROR=https://mirrors.tuna.tsinghua.edu.cn/debian
ARG DEBIAN_SECURITY_MIRROR=https://mirrors.tuna.tsinghua.edu.cn/debian-security

FROM ${COMPOSER_IMAGE} AS composer
FROM ${PHP_IMAGE} AS runtime
ARG DEBIAN_MIRROR
ARG DEBIAN_SECURITY_MIRROR

WORKDIR /usr/local/share/cypht

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_CACHE_DIR=/tmp/composer_cache \
    COMPOSER_HOME=/tmp/composer_home

# Install system packages and PHP extensions
RUN set -eux \
    && sed -i \
        -e "s|http://deb.debian.org/debian-security|${DEBIAN_SECURITY_MIRROR}|g" \
        -e "s|http://deb.debian.org/debian|${DEBIAN_MIRROR}|g" \
        /etc/apt/sources.list.d/debian.sources \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        supervisor \
        nginx \
        sqlite3 \
        libsqlite3-dev \
        libfreetype6-dev \
        libpng-dev \
        libjpeg-dev \
        libxml2-dev \
        libzip-dev \
        libcurl4-openssl-dev \
        libonig-dev \
        unzip \
        ca-certificates \
        curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        curl \
        dom \
        gd \
        mbstring \
        pdo_sqlite \
        soap \
        xml \
        xmlwriter \
        zip \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default \
    && mkdir -p /var/lib/hm3 /var/log/php /var/log/supervisord \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log \
    && ln -sf /dev/stdout /var/log/php/fpm.log \
    && ln -sf /dev/stderr /var/log/php/fpm-error.log \
    && ln -s /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

RUN printf "post_max_size = 60M \n\
upload_max_filesize = 50M \n\
open_basedir = /var/lib/hm3/:/usr/local/share/cypht/:/tmp:/usr/local/bin:/tmp/composer_cache:/tmp/composer_home:/etc" > /usr/local/etc/php/conf.d/cypht.ini

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/*

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf

COPY composer.* ./
RUN --mount=type=cache,target=/tmp/composer_cache \
    --mount=type=secret,id=github_token,required=false \
    set -eu; \
    if [ -s /run/secrets/github_token ]; then \
        github_token="$(cat /run/secrets/github_token)"; \
        case "$github_token" in *[!A-Za-z0-9_]*) exit 1;; esac; \
        export COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"$github_token\"}}"; \
    fi; \
    export GIT_CONFIG_COUNT=1 \
        GIT_CONFIG_KEY_0=http.version \
        GIT_CONFIG_VALUE_0=HTTP/1.1 \
        COMPOSER_ALLOW_SUPERUSER=1 \
        COMPOSER_MAX_PARALLEL_HTTP=4; \
    attempt=1; \
    until COMPOSER_PROCESS_TIMEOUT=1800 php -d default_socket_timeout=180 /usr/local/bin/composer install --no-interaction --no-progress --prefer-dist; do \
        if [ "$attempt" -ge 3 ]; then exit 1; fi; \
        attempt=$((attempt + 1)); \
        echo "Composer install failed; retrying ($attempt/3)" >&2; \
        sleep 5; \
    done

FROM runtime AS test

COPY assets/ assets/
COPY config/ config/
COPY language/ language/
COPY lib/ lib/
COPY modules/ modules/
COPY scripts/ scripts/
COPY database/ database/
COPY tests/ tests/
COPY third_party/ third_party/
COPY index.php index.php
COPY docker/.env.local-agent .env
COPY docker/.env.local-agent docker/.env.local-agent
COPY .env.test.example .env.test

CMD ["vendor/bin/phpunit", "-c", "tests/phpunit/phpunit.xml"]

FROM test AS local-agent-test

CMD ["tests/phpunit/run.sh", "--testsuite=modules_site,modules_api_login,db_session,user_config_db,oauth2,servers,modules_core,modules_saved_searches"]

FROM runtime AS production

RUN --mount=type=cache,target=/tmp/composer_cache \
    --mount=type=secret,id=github_token,required=false \
    set -eu; \
    if [ -s /run/secrets/github_token ]; then \
        github_token="$(cat /run/secrets/github_token)"; \
        case "$github_token" in *[!A-Za-z0-9_]*) exit 1;; esac; \
        export COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"$github_token\"}}"; \
    fi; \
    export GIT_CONFIG_COUNT=1 \
        GIT_CONFIG_KEY_0=http.version \
        GIT_CONFIG_VALUE_0=HTTP/1.1 \
        COMPOSER_ALLOW_SUPERUSER=1 \
        COMPOSER_MAX_PARALLEL_HTTP=4; \
    attempt=1; \
    until COMPOSER_PROCESS_TIMEOUT=1800 php -d default_socket_timeout=180 /usr/local/bin/composer install --no-dev --no-interaction --no-progress --prefer-dist; do \
        if [ "$attempt" -ge 3 ]; then exit 1; fi; \
        attempt=$((attempt + 1)); \
        echo "Composer install failed; retrying ($attempt/3)" >&2; \
        sleep 5; \
    done

COPY assets/ assets/
COPY config/ config/
COPY language/ language/
COPY lib/ lib/
COPY modules/ modules/
COPY scripts/ scripts/
COPY database/ database/
COPY third_party/ third_party/
COPY index.php index.php

# Entrypoint
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
COPY docker/.env.local-agent .env

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl --fail --silent --show-error http://127.0.0.1/site.css >/dev/null || exit 1

EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
