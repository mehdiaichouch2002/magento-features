# Tiny toolbox used by ./robo when the host has no PHP >= 8.2.
# It drives the host's Docker daemon through the mounted socket, so the only
# host requirement for this project is Docker itself.
FROM php:8.4-cli-alpine

RUN apk add --no-cache bash git unzip docker-cli docker-cli-compose

COPY --from=composer:2.10 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1
