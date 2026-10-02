# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:php8.5-bookworm AS php

RUN install-php-extensions bcmath gd intl mbstring opcache pcntl pdo_pgsql pgsql redis zip

WORKDIR /app

FROM php AS build

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:24-bookworm /usr/local /usr/local

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress

COPY . .
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && php artisan wayfinder:generate --with-form --no-interaction \
    && npm ci \
    && npm run build \
    && rm -rf node_modules

FROM php AS production

ENV OCTANE_SERVER=frankenphp \
    XDG_CONFIG_HOME=/app/storage/framework/frankenphp/config \
    XDG_DATA_HOME=/app/storage/framework/frankenphp/data

COPY --from=build --chown=www-data:www-data /app /app

RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/framework/frankenphp/config \
        storage/framework/frankenphp/data storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 8080

ENTRYPOINT ["sh", "-c", "php artisan storage:link --force --no-interaction && exec \"$@\"", "--"]

CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8080"]
