# syntax=docker/dockerfile:1
# Image de PRODUCTION de l'API Symfony (recette et production, T-04).
# Construite par la CD depuis la racine du dépôt :
#   docker build -f docker/api/Dockerfile -t sidekey-api .
# La même image sert l'API (php-fpm), le worker (messenger:consume) et les migrations.
# L'image de développement reste docker/php/Dockerfile (Xdebug, code monté en volume).

FROM php:8.4-fpm-alpine AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
# Mêmes extensions que le développement, sans Xdebug
RUN install-php-extensions \
        pdo_pgsql \
        intl \
        zip \
        opcache \
        apcu \
        redis \
        sodium

COPY docker/api/php.prod.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/api/fpm.prod.conf /usr/local/etc/php-fpm.d/zz-sidekey.conf

WORKDIR /var/www/api


# --- Dépendances et cache Symfony (étape jetable : Composer ne va pas dans l'image finale) ---
FROM base AS build

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=prod \
    APP_DEBUG=0

# Couche de dépendances : reconstruite seulement si composer.lock change
COPY api/composer.json api/composer.lock api/symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

COPY api/ ./
# Seul APP_ENV est fixé dans le fichier : tout le reste vient des variables d'environnement
# du conteneur (compose de déploiement), et Symfony refuse de démarrer s'il en manque une.
# Les valeurs passées à cache:warmup sont factices : rien ne se connecte pendant le build, et elles
# ne restent pas dans l'image (variables de la seule commande RUN).
RUN printf 'APP_ENV=prod\n' > .env \
 && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
 && APP_SECRET=build-uniquement \
    DATABASE_URL='postgresql://build:build@localhost:5432/build?serverVersion=17' \
    REDIS_URL='redis://localhost:6379' \
    DEFAULT_URI='http://localhost' \
    php bin/console cache:warmup --no-interaction \
 && rm -f /usr/local/bin/composer


# --- Image finale ---
FROM base AS prod

ENV APP_ENV=prod \
    APP_DEBUG=0

COPY --from=build /var/www/api /var/www/api
# Code en lecture seule (propriété de root) ; seul var/ (cache, journaux) est inscriptible
RUN chown -R www-data:www-data var

USER www-data

CMD ["php-fpm"]
