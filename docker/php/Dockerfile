# syntax=docker/dockerfile:1
# TENDRIX API image (PHP-FPM). Build context: repository root.

FROM php:8.3-fpm-alpine AS base
RUN apk add --no-cache icu-libs libpq libzip fcgi \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev linux-headers \
 && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql opcache zip \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del .build-deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini $PHP_INI_DIR/conf.d/zz-tendrix.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-tendrix.conf
WORKDIR /app

FROM base AS vendor
ENV APP_ENV=prod COMPOSER_ALLOW_SUPERUSER=1
COPY backend/composer.json backend/composer.lock backend/symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

FROM base AS prod
ENV APP_ENV=prod APP_DEBUG=0
COPY --from=vendor /app/vendor ./vendor
COPY backend/ ./
RUN rm -rf var/* config/jwt/*.pem tests php.ini \
 && composer dump-autoload --classmap-authoritative --no-dev \
 && composer dump-env prod \
 && php bin/console assets:install public --no-interaction \
 && chown -R www-data:www-data var
COPY docker/php/entrypoint.sh /usr/local/bin/tendrix-entrypoint
RUN chmod +x /usr/local/bin/tendrix-entrypoint
HEALTHCHECK --interval=15s --timeout=3s CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1
ENTRYPOINT ["tendrix-entrypoint"]
CMD ["php-fpm"]
