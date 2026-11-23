# Image PHP-FPM de développement pour l'API Symfony
FROM php:8.4-fpm-alpine

# Installeur d'extensions PHP (gère les dépendances système de chaque extension)
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        pdo_pgsql \
        intl \
        zip \
        opcache \
        apcu \
        redis \
        sodium \
        xdebug

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY conf.d/app.dev.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/api

# Xdebug installé mais désactivé par défaut : XDEBUG_MODE=debug dans .env pour l'activer
ENV XDEBUG_MODE=off \
    COMPOSER_ALLOW_SUPERUSER=1

CMD ["php-fpm"]
