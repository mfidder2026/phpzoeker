# PHP Zoeker — productie-image (Railway bouwt dit op hun servers; lokaal is Docker niet nodig).
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev libpng-dev libpq-dev libxml2-dev \
    && docker-php-ext-install intl zip gd pdo_pgsql pgsql bcmath exif sockets pcntl opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* \
    && printf "memory_limit=512M\nupload_max_filesize=25M\npost_max_size=30M\nopcache.enable_cli=1\nopcache.validate_timestamps=0\n" > /usr/local/etc/php/conf.d/phpzoeker.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader

COPY . .

RUN composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi \
    && php artisan filament:assets \
    && chmod +x railway/start.sh

ENV PORT=8000 \
    PHP_CLI_SERVER_WORKERS=4

EXPOSE 8000
CMD ["railway/start.sh"]
