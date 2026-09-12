# syntax=docker/dockerfile:1.7
# Immoware Hub, Laufzeit-Image (php-fpm). Kein Frontend-Build, kein Node.
# Ein Image fuer app, worker und scheduler; die Rolle bestimmt der Befehl in compose.yaml.

ARG PHP_VERSION=8.4

# ---------------------------------------------------------------------------
# Stufe 1: Composer-Abhaengigkeiten ohne Dev-Pakete
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS vendor

RUN apk add --no-cache git unzip icu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" intl zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev --no-interaction --no-progress --no-scripts \
        --prefer-dist --optimize-autoloader --classmap-authoritative

# ---------------------------------------------------------------------------
# Stufe 2: Laufzeit
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS runtime

LABEL org.opencontainers.image.title="Immoware Hub" \
      org.opencontainers.image.vendor="Hausverwaltung Mueller GmbH" \
      org.opencontainers.image.description="Integrationsschicht um den Immoware24-Mandanten (Laravel 13)"

# Laufzeitbibliotheken und Build-Werkzeuge fuer die PHP-Erweiterungen.
# redis kommt ueber pecl (kein passendes Alpine-Paket fuer das offizielle php-Image).
RUN set -eux; \
    apk add --no-cache icu-libs libzip mariadb-client gnupg curl tzdata; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev linux-headers; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip opcache pcntl bcmath; \
    pecl install redis-6.2.0; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    rm -rf /tmp/pear /tmp/*

COPY docker/php/php.ini      /usr/local/etc/php/conf.d/90-immoware.ini
COPY docker/php/opcache.ini  /usr/local/etc/php/conf.d/91-opcache.ini
COPY docker/php/www.conf     /usr/local/etc/php-fpm.d/zz-immoware.conf
COPY docker/entrypoint.sh    /usr/local/bin/immoware-entrypoint

WORKDIR /var/www/html

# Anwendungscode (Ausschluesse ueber .dockerignore) und Vendor aus Stufe 1
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /build/vendor ./vendor

RUN set -eux; \
    chmod +x /usr/local/bin/immoware-entrypoint; \
    mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
             storage/logs storage/app/imports storage/app/private bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache; \
    chmod -R ug+rwX storage bootstrap/cache; \
    rm -f .env .env.example.local

USER www-data

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r 'exit((int) !@fsockopen("127.0.0.1", 9000));'

ENTRYPOINT ["immoware-entrypoint"]
CMD ["php-fpm"]
