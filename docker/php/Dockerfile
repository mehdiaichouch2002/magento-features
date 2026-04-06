FROM php:8.3-fpm-bookworm

ARG XDEBUG_ENABLED=false

# ─── System dependencies ──────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip zip curl \
    libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev \
    libxml2-dev libxslt1-dev libssl-dev libicu-dev libsodium-dev \
    libonig-dev libmagickwand-dev imagemagick \
    procps vim mariadb-client \
    && rm -rf /var/lib/apt/lists/*

# ─── PHP extensions ───────────────────────────────────────────────────────────
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        calendar \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pdo_mysql \
        soap \
        sockets \
        sodium \
        xsl \
        zip

# ─── PECL extensions ──────────────────────────────────────────────────────────
RUN pecl install redis imagick \
    && docker-php-ext-enable redis imagick

# ─── Xdebug (only when XDEBUG_ENABLED=true) ──────────────────────────────────
RUN if [ "$XDEBUG_ENABLED" = "true" ]; then \
        pecl install xdebug \
        && docker-php-ext-enable xdebug \
        && { \
            echo "xdebug.mode=debug,develop"; \
            echo "xdebug.start_with_request=yes"; \
            echo "xdebug.client_host=host.docker.internal"; \
            echo "xdebug.client_port=9003"; \
            echo "xdebug.idekey=PHPSTORM"; \
            echo "xdebug.log_level=0"; \
        } >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; \
    fi

# ─── Composer (used by both FPM and maintenance) ──────────────────────────────
COPY --from=composer:2.7 /usr/bin/composer /usr/local/bin/composer

# ─── Node.js LTS (for frontend builds in maintenance container) ───────────────
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g npm@latest \
    && rm -rf /var/lib/apt/lists/*

# ─── Permissions: align uid/gid with typical dev host (1000) ─────────────────
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

WORKDIR /var/www/html

EXPOSE 9000
CMD ["php-fpm"]
