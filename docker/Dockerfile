# syntax=docker/dockerfile:1.7
# Local development / CI image. Laravel Cloud builds from the repository and
# does not use this file in production.
FROM dunglas/frankenphp:1-php8.4-bookworm AS base

RUN install-php-extensions \
        pdo_mysql redis intl zip bcmath pcntl gd exif opcache sockets \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip default-mysql-client curl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# Headless Chromium for Pest browser tests (dev/CI only).
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
RUN npx -y playwright@1.64.0 install --with-deps chromium-headless-shell \
    && rm -rf /var/lib/apt/lists/* /root/.npm

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-noise-monitor.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    SERVER_NAME=:8080

WORKDIR /app
EXPOSE 8080
