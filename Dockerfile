FROM php:8.3-cli AS runtime

# PCOV: fast coverage driver, enabled by default for `phpunit --coverage-*`.
# Xdebug: step-debugging + fallback coverage driver, coverage/debug mode off by default
# (toggle at runtime with -e XDEBUG_MODE=coverage or =debug) so normal test runs stay fast.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql \
    && pecl install pcov xdebug \
    && docker-php-ext-enable pcov xdebug \
    && { \
        echo 'pcov.enabled=1'; \
        echo 'pcov.directory=/var/www/html/app'; \
    } > /usr/local/etc/php/conf.d/pcov.ini \
    && { \
        echo 'xdebug.mode=off'; \
        echo 'xdebug.discover_client_host=1'; \
        echo 'xdebug.client_host=host.docker.internal'; \
    } > /usr/local/etc/php/conf.d/xdebug-custom.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist

COPY app ./app
COPY config ./config
COPY public ./public
COPY scripts ./scripts
COPY views ./views
COPY database/migrations ./database/migrations

RUN mkdir -p /var/www/html/var/log /var/www/html/var/sessions \
    && chown -R www-data:www-data /var/www/html

USER www-data

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/router.php"]

FROM node:22-bookworm-slim AS node-runtime

FROM runtime AS test
USER root
COPY --from=node-runtime /usr/local/bin/node /usr/local/bin/node
COPY tests ./tests
COPY database ./database
COPY phpunit.xml phpstan.neon ./
RUN chown -R www-data:www-data /var/www/html
USER www-data
CMD ["php", "scripts/quality-check.php"]

# Production has no debugger, coverage driver or development dependencies.
FROM php:8.3-fpm AS production
RUN docker-php-ext-install pdo_mysql opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
COPY app ./app
COPY config ./config
COPY public ./public
COPY scripts ./scripts
COPY views ./views
COPY deploy/php/production.ini /usr/local/etc/php/conf.d/production.ini
COPY database/migrations ./database/migrations
COPY deploy/php/pool.conf /usr/local/etc/php-fpm.d/zz-production.conf
RUN mkdir -p var/log var/sessions && chown -R www-data:www-data var
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]

FROM nginx:stable-alpine AS web
COPY public /var/www/html/public
COPY deploy/nginx/default.conf /etc/nginx/conf.d/default.conf
