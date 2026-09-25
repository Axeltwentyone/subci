# API Sub.ci (Laravel) pour Render : Nginx + PHP-FPM + file d'attente + planificateur, un seul conteneur.
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache nginx supervisor postgresql-libs libpng libjpeg-turbo libwebp freetype icu-libs libzip gmp \
 && apk add --no-cache --virtual .build $PHPIZE_DEPS postgresql-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev icu-dev libzip-dev gmp-dev linux-headers \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql gd intl zip bcmath pcntl opcache exif gmp \
 && apk del .build

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-subci.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-subci.conf
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dépendances d'abord (cache Docker), code ensuite.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && php artisan package:discover --ansi \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Render fournit $PORT (10000 par défaut).
ENV PORT=10000
EXPOSE 10000
ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
