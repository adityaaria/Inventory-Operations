FROM php:8.3-cli

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

COPY app config public scripts views ./

RUN mkdir -p /var/www/html/var/log \
    && chown -R www-data:www-data /var/www/html

USER www-data

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/router.php"]
