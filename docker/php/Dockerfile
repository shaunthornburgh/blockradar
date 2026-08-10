# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Base — PHP 8.3 FPM with the extensions Laravel + MySQL + Redis need.
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
        bash \
        git \
        icu-dev \
        libzip-dev \
        linux-headers \
        oniguruma-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    # Toolchain is only needed to build the extensions above.
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-block-radar.ini
# Replaces the shipped pool rather than adding a second [www] section.
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Run as a non-root user whose uid matches the host, so files written into the
# bind mount (logs, cache, migrations) stay editable outside the container.
ARG UID=1000
ARG GID=1000
RUN addgroup -g "${GID}" app 2>/dev/null || addgroup app \
    && adduser -D -u "${UID}" -G app -h /var/www -s /bin/bash app 2>/dev/null \
       || adduser -D -G app -h /var/www -s /bin/bash app

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# Development — source arrives via a bind mount, so only vendor/ is warmed.
# ---------------------------------------------------------------------------
FROM base AS development

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# A fresh named volume inherits the ownership of the image directory it covers.
# Without this, the vendor/ volume is created root-owned and composer install
# fails for the unprivileged app user.
RUN mkdir -p /var/www/html/vendor \
    && chown -R app:app /var/www/html

USER app

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Production — dependencies and source baked into the image.
# ---------------------------------------------------------------------------
FROM base AS production

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY backend/composer.json backend/composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY backend/ ./

RUN composer dump-autoload --optimize --classmap-authoritative \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R app:app storage bootstrap/cache

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

USER app

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
