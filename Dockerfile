# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1: build front-end assets
# ---------------------------------------------------------------------------
FROM node:20-alpine AS assets

WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
RUN npm run build


# ---------------------------------------------------------------------------
# Stage 2: PHP dependencies
#
# PHP 8.4 is required, not optional: composer.lock pins Symfony 8 components
# that declare php >=8.4.
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS vendor

RUN apk add --no-cache $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip bcmath opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
# No scripts/autoloader yet - artisan isn't present, so package discovery would fail.
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative


# ---------------------------------------------------------------------------
# Stage 3: runtime - nginx + php-fpm + queue worker under supervisord
#
# Web and worker share one container deliberately. Railway volumes attach to a
# single service, and the worker WRITES inbound attachments while the web
# process READS them for download, so they must share a filesystem.
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS runtime

RUN apk add --no-cache \
      nginx supervisor postgresql-libs icu-libs libzip gettext \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip bcmath opcache \
    && apk del .build-deps

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html
COPY --from=assets /build/public/build /var/www/html/public/build

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && chown -R www-data:www-data /var/www/html

# Railway injects PORT; nginx is templated against it at startup.
ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
