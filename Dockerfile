FROM php:8.5-fpm AS app

# Biblioteki systemowe potrzebne do rozszerzeń PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libicu-dev libzip-dev unzip git \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip pcntl bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# Zależności osobno, żeby cache warstw działał przy zmianach kodu
ARG INSTALL_DEV=0
COPY composer.json composer.lock ./
RUN if [ "$INSTALL_DEV" = "1" ]; then composer install --no-interaction --no-scripts --no-autoloader; \
    else composer install --no-interaction --no-scripts --no-autoloader --no-dev; fi

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    # Zmienne przychodzą z env_file w compose, pusty .env tylko po to, żeby phpdotenv nie ostrzegał
    && touch .env \
    && chown -R www-data:www-data /var/www/html

USER www-data
CMD ["php-fpm"]

# Caddy z plikami public/ z obrazu aplikacji (CSS i JS Filamenta pasują wtedy do kodu)
FROM caddy:2 AS web
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY --from=app /var/www/html/public /var/www/html/public
