ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

FROM node:${NODE_VERSION}-bookworm-slim AS node

FROM serversideup/php:${PHP_VERSION}-frankenphp-bookworm AS php-base

USER root

RUN install-php-extensions exif gd intl

USER www-data

FROM php-base AS build

USER root

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm

RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

USER www-data
WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock package.json package-lock.json .npmrc ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

RUN npm ci

COPY --chown=www-data:www-data . .

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist

ARG VITE_APP_NAME=estatell

ENV VITE_APP_NAME=${VITE_APP_NAME}

RUN npm run build
RUN rm -rf node_modules

FROM php-base AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    SSL_MODE=off \
    HEALTHCHECK_PATH=/up \
    PHP_OPCACHE_ENABLE=1 \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info

USER www-data
WORKDIR /var/www/html

COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html

EXPOSE 8080
