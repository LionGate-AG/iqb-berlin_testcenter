ARG REGISTRY_PATH=""
ARG PHP_VERSION=8.3-fpm-bookworm

# xsd download stage
FROM ${REGISTRY_PATH}composer:lts AS schema-downloader
RUN mkdir -p /var/www/testcenter/data/.schemas
COPY scripts/download-schemas.sh /tmp/download-schemas.sh
COPY definitions/compatibility.json /tmp/compatibility.json
RUN chmod +x /tmp/download-schemas.sh && /tmp/download-schemas.sh /tmp/compatibility.json


FROM ${REGISTRY_PATH}composer:lts AS dev-composer
WORKDIR /usr/src/testcenter/backend

# resolving and downloading the dependencies depends on composer.lock alone, so it
# is done before the code is copied and stays cached across source code changes
RUN --mount=type=bind,source=backend/composer.json,target=composer.json \
    --mount=type=bind,source=backend/composer.lock,target=composer.lock \
    --mount=type=cache,target=/tmp/cache \
    composer install \
      --no-interaction \
      --no-autoloader \
      --ignore-platform-reqs

COPY backend/src ./src/
COPY backend/test/unit/test-helper ./test/unit/test-helper

# the classmap is the only part of the autoloader that needs the code
RUN --mount=type=bind,source=backend/composer.json,target=composer.json \
    --mount=type=bind,source=backend/composer.lock,target=composer.lock \
    composer dump-autoload \
      --no-interaction \
      --ignore-platform-reqs


FROM ${REGISTRY_PATH}composer:lts AS prod-composer
WORKDIR /usr/src/testcenter/backend

# resolving and downloading the dependencies depends on composer.lock alone, so it
# is done before the code is copied and stays cached across source code changes
RUN --mount=type=bind,source=backend/composer.json,target=composer.json \
    --mount=type=bind,source=backend/composer.lock,target=composer.lock \
    --mount=type=cache,target=/tmp/cache \
    composer install \
      --no-interaction \
      --no-dev \
      --no-autoloader \
      --ignore-platform-reqs

COPY backend/src ./src/

# the classmap is the only part of the autoloader that needs the source code
RUN --mount=type=bind,source=backend/composer.json,target=composer.json \
    --mount=type=bind,source=backend/composer.lock,target=composer.lock \
    composer dump-autoload \
      --no-interaction \
      --no-dev \
      --ignore-platform-reqs


FROM ${REGISTRY_PATH}php:${PHP_VERSION} AS system
# Install PHP extensions and nginx (the web server in front of php-fpm)
RUN --mount=type=cache,sharing=locked,target=/var/cache/apt \
    apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev libpq-dev \
    nginx


RUN docker-php-ext-install -j$(nproc) pdo_pgsql zip opcache
RUN pecl install igbinary && docker-php-ext-enable igbinary
RUN pecl install redis && docker-php-ext-enable redis

# Configure php-fpm pool (listen on loopback, keep env, log to stderr).
# (local.php.ini is copied in the base stage: its open_basedir would break the pecl builds above.)
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



# dev extensions are built here, before any application code is copied, so that
# code changes do not invalidate the (slow) xdebug/pcntl compilation
FROM system AS dev-extension
RUN pecl install xdebug-3.4.1 && docker-php-ext-enable xdebug   # @TODO: change xdebug and php release (xdebug-3.4.2 does not work with php-8.3.12!)
RUN docker-php-ext-install pcntl && docker-php-ext-enable pcntl


FROM system AS base
# Configure PHP runtime
COPY backend/config/local.php.ini /usr/local/etc/php/conf.d/local.ini

# Copy backend code
WORKDIR /var/www/testcenter/backend/

RUN chown -R www-data:www-data /var/www
RUN mkdir ./config && chown www-data:www-data ./config
RUN mkdir -p ../data/.schemas && chown -R www-data:www-data ../data
COPY --chown=www-data:www-data --from=schema-downloader /var/www/testcenter/data/.schemas/ ../data/.schemas/

COPY --chown=www-data:www-data definitions ../definitions
COPY --chown=www-data:www-data scripts/database ../scripts/database
COPY --chown=www-data:www-data sampledata ../sampledata
COPY --chown=www-data:www-data package.json ../package.json

COPY --chown=www-data:www-data backend/check-db-compatibility.php .
COPY --chown=www-data:www-data backend/index.php .
COPY --chown=www-data:www-data backend/initialize.php .
COPY --chown=www-data:www-data backend/routes.php .
# Entrypoints of the log-drainer and state-drainer Deployments: same image, different command. They
# must be listed here -- this stage copies named root files rather than the whole backend directory,
# so a new top-level script would silently be absent otherwise.
COPY --chown=www-data:www-data backend/drain-logs.php .
COPY --chown=www-data:www-data backend/drain-test-states.php .
COPY --chown=www-data:www-data backend/src ./src


VOLUME /var/www/testcenter/data
EXPOSE 8080

COPY backend/entrypoint.sh /entrypoint.sh
COPY backend/initialize_only.sh /initialize_only.sh
COPY backend/run-server.sh /run-server.sh

ENTRYPOINT ["/entrypoint.sh"]


FROM base AS dev
# copy PHP dev extensions built in the dev-extension stage to the right spot
COPY --from=dev-extension /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=dev-extension /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

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
USER www-data
