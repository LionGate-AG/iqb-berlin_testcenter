ARG REGISTRY_PATH=""
ARG PHP_VERSION=8.3-fpm-bookworm

# xsd download stage
FROM ${REGISTRY_PATH}composer:lts AS schema-downloader
# Verzeichnis für das Script erstellen
RUN mkdir -p /var/www/testcenter/data/.schemas
# Script kopieren und ausführen
COPY scripts/download-schemas.sh /tmp/download-schemas.sh
RUN chmod +x /tmp/download-schemas.sh && /tmp/download-schemas.sh


FROM ${REGISTRY_PATH}composer:lts AS dev-composer
WORKDIR /usr/src/testcenter/backend
COPY backend/src ./src/
COPY backend/test/unit/test-helper ./test/unit/test-helper

RUN --mount=type=bind,source=backend/composer.json,target=composer.json \
    --mount=type=bind,source=backend/composer.lock,target=composer.lock \
    --mount=type=cache,target=/tmp/cache \
    composer install \
      --no-interaction \
      --ignore-platform-reqs


FROM ${REGISTRY_PATH}composer:lts AS prod-composer
WORKDIR /usr/src/testcenter/backend

# add cache for xsd files
RUN mkdir -p /var/www/testcenter/data/.schemas
COPY scripts/download-schemas.sh /tmp/download-schemas.sh
RUN chmod +x /tmp/download-schemas.sh && /tmp/download-schemas.sh

COPY backend/src ./src/

RUN --mount=type=bind,source=backend/composer.json,target=composer.json \
    --mount=type=bind,source=backend/composer.lock,target=composer.lock \
    --mount=type=cache,target=/tmp/cache \
    composer install \
      --no-interaction \
      --no-dev \
      --ignore-platform-reqs


FROM ${REGISTRY_PATH}php:${PHP_VERSION} AS base
# Install PHP extensions and nginx (the web server in front of php-fpm)
RUN --mount=type=cache,sharing=locked,target=/var/cache/apt \
    apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev \
    nginx

RUN docker-php-ext-install -j$(nproc) pdo_mysql zip opcache
RUN pecl install igbinary && docker-php-ext-enable igbinary
RUN pecl install redis && docker-php-ext-enable redis

# Configure PHP runtime
COPY backend/config/local.php.ini /usr/local/etc/php/conf.d/local.ini

# Configure php-fpm pool (listen on loopback, keep env, log to stderr)
COPY backend/config/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-testcenter.conf

# Configure nginx (front controller + headers; rootless on :8080)
COPY backend/config/nginx.conf /etc/nginx/nginx.conf
# Empty CORS include by default; the dev compose mounts a permissive cors.conf
RUN mkdir -p /etc/nginx/conf.d && : > /etc/nginx/conf.d/cors.conf
# Writable temp dirs for the rootless nginx worker (runs as www-data)
RUN mkdir -p /tmp/nginx-client-body /tmp/nginx-proxy /tmp/nginx-fastcgi \
             /tmp/nginx-uwsgi /tmp/nginx-scgi \
 && chown -R www-data:www-data /tmp/nginx-client-body /tmp/nginx-proxy \
             /tmp/nginx-fastcgi /tmp/nginx-uwsgi /tmp/nginx-scgi

# Copy backend code
WORKDIR /var/www/testcenter/backend/
RUN mkdir ./config

COPY backend/index.php .
COPY backend/initialize.php .
COPY backend/routes.php .
COPY backend/src ./src

RUN mkdir -p ../data/.schemas
COPY --from=schema-downloader /var/www/testcenter/data/.schemas/ ../data/.schemas/
COPY definitions ../definitions
COPY package.json ../package.json
COPY sampledata ../sampledata
COPY scripts/database ../scripts/database

RUN chown -R www-data:www-data /var/www

VOLUME /var/www/testcenter/data
EXPOSE 8080

COPY backend/entrypoint.sh /entrypoint.sh
COPY backend/initialize_only.sh /initialize_only.sh
COPY backend/run-server.sh /run-server.sh

ENTRYPOINT ["/entrypoint.sh"]


FROM base AS dev
# Install PHP dev extensions
RUN pecl install xdebug-3.4.1 && docker-php-ext-enable xdebug   # @TODO: change xdebug and php release (xdebug-3.4.2 does not work with php-8.3.12!)
RUN docker-php-ext-install pcntl && docker-php-ext-enable pcntl

# Copy dev dependencies
COPY --chown=www-data:www-data --from=dev-composer /usr/src/testcenter/backend/vendor/ vendor/

# Add testing code
COPY --chown=www-data:www-data backend/phpunit.xml .
COPY --chown=www-data:www-data backend/test ./test

# some initialization tests need this
# jq - JSON parser for bash
RUN --mount=type=cache,sharing=locked,target=/var/cache/apt \
    apt-get update && apt-get install -y --no-install-recommends \
    jq

USER www-data


FROM base AS prod
# Copy prod dependencies
COPY --chown=www-data:www-data --from=prod-composer /usr/src/testcenter/backend/vendor/ ./vendor/
# Copy xsd files in cache folder
COPY --chown=www-data:www-data --from=prod-composer /var/www/testcenter/data/.schemas/ ../data/.schemas/
USER www-data
