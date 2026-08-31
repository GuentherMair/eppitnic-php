# Builds and serves eppitnic's REST API from one image: nginx in front,
# php-fpm behind it over a unix socket, both under tini. See README.md's
# Docker section for how the pieces (this file, compose.yaml, docker/) fit
# together, and the plan this was built from for the measurements behind a
# few choices below that would otherwise look arbitrary.

# One stage for the PHP platform, shared by the two below, so that the image
# resolving the dependencies is the image that will run them. Building in the
# composer image instead means `composer install` validates composer.json
# against a platform that is not the runtime's -- it has no pdo_mysql, so the
# build fails outright, and --ignore-platform-req would only silence the check
# until the next extension is added.
#
# pdo_mysql is the one extension this base image lacks, verified against real
# usage: the DSN is always mysql: (Config, Setup\DatabaseCredentials) and
# SchemaInstaller's own `SHOW TABLES` check is MySQL-only. The alpine images
# ship no compiler, so $PHPIZE_DEPS goes in and comes straight back out -- it
# is build tooling, not something to ship.
FROM php:8.5-fpm-alpine AS base
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql \
    && apk del .build-deps

FROM base AS builder
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
# the full tree, not just composer.json/.lock -- --optimize-autoloader scans
# src/ to build its class map, so it needs the real source present, and
# .dockerignore already keeps vendor/, tests/ and the rest of the build
# context down to what this actually uses
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

FROM base AS runtime

# nginx: this application's own front controller, not the base image's.
# tini: a minimal init as PID 1, so `docker stop` reaches start-web.sh as a
#   real signal instead of only stopping tini itself.
# su-exec: drops from root to www-data before running one-shot CLI verbs
#   (see the eppitnic-cli-* compose services) -- config.php, the EPP cookie
#   jar and selftest notes then end up owned the same as the web/cron
#   processes that read them back, rather than root-owned and unreadable to
#   the www-data worker that needs them next.
RUN apk add --no-cache nginx tini su-exec \
    && docker-php-ext-enable opcache \
    && rm -f /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/www.conf.default \
    && rm -f /etc/nginx/http.d/default.conf \
    && mkdir -p /run/php

WORKDIR /app

# xsd/ is never read at runtime -- only its filenames appear, as
# xsi:schemaLocation string literals in src/Epp/XmlBuilder.php -- so it's
# left out here rather than copied and then ignored.
COPY --from=builder /app/bin            ./bin
COPY --from=builder /app/config         ./config
COPY --from=builder /app/public         ./public
COPY --from=builder /app/src            ./src
COPY --from=builder /app/vendor         ./vendor
COPY --from=builder /app/composer.json  ./composer.json

RUN ln -s /app/bin/eppitnic /usr/local/bin/eppitnic \
    && chmod +x /app/bin/eppitnic

COPY docker/php.ini                  /usr/local/etc/php/conf.d/eppitnic.ini
COPY docker/php-fpm.d/eppitnic.conf  /usr/local/etc/php-fpm.d/eppitnic.conf
COPY docker/nginx.conf               /etc/nginx/http.d/default.conf
COPY docker/crontab                  /etc/crontabs/root
COPY docker/entrypoint.sh            /docker/entrypoint.sh
COPY docker/start-web.sh             /docker/start-web.sh
RUN chmod +x /docker/entrypoint.sh /docker/start-web.sh \
    && chmod 600 /etc/crontabs/root

# The container-side halves of the two per-instance paths (see
# src/Setup/ConfigFile.php and src/Selftest/Leftovers.php) are fixed by the
# image, not by the instance: what actually varies between instances is the
# host side of the /data bind mount and the published port, both set in
# compose.yaml/compose.multi.yaml.sample. Override these with `-e` for a
# different in-container layout; no instance should need to.
ENV EPPITNIC_CONFIG_DIR=/data/config \
    EPPITNIC_VAR_DIR=/data/var

EXPOSE 80

ENTRYPOINT ["/sbin/tini", "--", "/docker/entrypoint.sh"]
CMD ["/docker/start-web.sh"]
