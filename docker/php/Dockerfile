# syntax=docker/dockerfile:1
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-fpm-trixie

ARG XDEBUG_ENABLED=false

# ─── System dependencies ──────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip curl ca-certificates gnupg \
        procps vim mariadb-client msmtp \
    && rm -rf /var/lib/apt/lists/*

# ─── PHP extensions (Magento 2.4.9 requirements) ──────────────────────────────
# install-php-extensions picks the right build deps and PECL versions for the
# current PHP release, so bumping PHP_VERSION needs no changes here.
ADD https://github.com/mlocati/docker-php-extension-installer/releases/download/2.12.0/install-php-extensions \
    /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions \
        bcmath \
        calendar \
        exif \
        ftp \
        gd \
        intl \
        opcache \
        pdo_mysql \
        redis \
        soap \
        sockets \
        sodium \
        xsl \
        zip

# ─── Xdebug (only when XDEBUG_ENABLED=true) ──────────────────────────────────
RUN if [ "$XDEBUG_ENABLED" = "true" ]; then \
        install-php-extensions xdebug \
        && { \
            echo "xdebug.mode=debug,develop"; \
            echo "xdebug.start_with_request=yes"; \
            echo "xdebug.client_host=host.docker.internal"; \
            echo "xdebug.client_port=9003"; \
            echo "xdebug.idekey=PHPSTORM"; \
            echo "xdebug.log_level=0"; \
        } >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; \
    fi

# ─── Composer ─────────────────────────────────────────────────────────────────
COPY --from=composer:2.10 /usr/bin/composer /usr/local/bin/composer

# ─── Node.js LTS (frontend builds in the maintenance container) ───────────────
COPY --from=node:22-trixie-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-trixie-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# ─── Permissions: align uid/gid with typical dev host (1000) ─────────────────
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

WORKDIR /var/www/html

EXPOSE 9000
CMD ["php-fpm"]
