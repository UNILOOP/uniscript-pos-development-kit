ARG UPSTREAM_IMAGE=php:8.4-cli-alpine
FROM ${UPSTREAM_IMAGE}
RUN apk add --no-cache libzip-dev icu-dev && docker-php-ext-install -j2 zip bcmath intl
