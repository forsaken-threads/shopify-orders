# Base image for local development: PHP 8.4 + nginx + SQLite + intl.
# No composer / node / mysql / redis — this app is raw PHP + SQLite.
FROM php:8.4-fpm-alpine AS base

USER root

WORKDIR /var/www

# Runtime + build dependencies. icu-dev is needed to build the intl extension
# (used by app/normalize.php for diacritic-insensitive search). sqlite is the
# CLI client, handy when poking at orders.sqlite from inside the container.
RUN apk update && apk upgrade && apk add \
    bash curl nginx autoconf tzdata nano openssl icu-dev sqlite-dev openssh-client less gnupg linux-headers gcc g++ make

RUN docker-php-ext-install pdo_sqlite intl opcache \
    && docker-php-ext-enable opcache

# PHP and nginx configuration.
COPY .deployment/config/local.ini /usr/local/etc/php/php.ini
COPY .deployment/config/fpm.conf  /usr/local/etc/php-fpm.d/zzzz.conf
COPY .deployment/config/nginx.conf /etc/nginx/nginx.conf

ARG COMMIT_HASH
ARG SOURCE_UID
ARG SOURCE_GID

# For local development, run nginx and php-fpm as the developer's UID/GID so
# files created inside the container are owned by the host user. Skipped when
# either variable is unset.
RUN if [ -n "${SOURCE_UID:+1}" ] & [ -n "${SOURCE_GID:+1}" ]; then \
        ((getent group $SOURCE_GID && groupmod -n centnotes "$(getent group $SOURCE_GID | cut -d: -f 1)") || addgroup --gid $SOURCE_GID centnotes) \
        && adduser -D --uid $SOURCE_UID -G centnotes --home /centnotes --shell /bin/bash centnotes \
        && touch /run/nginx.pid \
        && chown $SOURCE_UID:$SOURCE_GID /run/nginx.pid \
        && chown -Rf $SOURCE_UID:$SOURCE_GID /var/lib/nginx \
        && sed -i 's/www-data/centnotes/' /etc/nginx/nginx.conf \
        && sed -i 's/www-data/centnotes/' /usr/local/etc/php-fpm.d/www.conf; \
    fi

WORKDIR /var/www

ENTRYPOINT php-fpm -D && nginx -g "pid /run/nginx.pid; daemon off;"
