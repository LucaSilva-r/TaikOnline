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

# Waddamburo's scorer (its --rescore mode): plays are scored from their replays with the game's own
# rules. Pinned to a release; a scoring change ships as a new release here, then
# `php artisan app:rescore-waddamburo-plays --all`.
FROM debian:bookworm-slim AS waddamburo

ARG WADDAMBURO_VERSION=v0.17.0

ADD https://github.com/LucaSilva-r/Waddamburo-public/releases/download/${WADDAMBURO_VERSION}/Waddamburo-x86_64.AppImage /tmp/Waddamburo.AppImage
# Extracted: a container has no FUSE to mount the AppImage.
RUN cd /tmp && chmod +x Waddamburo.AppImage && ./Waddamburo.AppImage --appimage-extract >/dev/null \
    && mv squashfs-root /opt/waddamburo

FROM php AS production

# Match the host user that owns the bind-mounted storage directory.
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

ENV OCTANE_SERVER=frankenphp \
    WADDAMBURO_SCORER=/opt/waddamburo/AppRun \
    XDG_CONFIG_HOME=/app/storage/framework/frankenphp/config \
    XDG_DATA_HOME=/app/storage/framework/frankenphp/data

COPY --from=build --chown=www-data:www-data /app /app
COPY --from=waddamburo /opt/waddamburo /opt/waddamburo

RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/framework/frankenphp/config \
        storage/framework/frankenphp/data storage/logs bootstrap/cache \
    && ln -s /app/storage/app/public /app/public/storage \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 8080

CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8080"]
