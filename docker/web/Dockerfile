# syntax=docker/dockerfile:1
# Image de PRODUCTION du front (recette et production, T-04) : build Vite servi par Caddy.
# Caddy est aussi le point d'entrée HTTP : fichiers statiques du front, et /api/* relayé à php-fpm.
# Construite par la CD depuis la racine du dépôt :
#   docker build -f docker/web/Dockerfile -t sidekey-web .

FROM node:24-alpine AS build

WORKDIR /app
COPY front/package.json front/package-lock.json ./
RUN npm ci --no-fund --no-audit

COPY front/ ./
# Le front appelle l'API sur sa propre origine (même origine : pas de CORS, cookies SameSite=Strict)
ENV VITE_API_URL=/api \
    VITE_DEFAULT_LOCALE=fr
RUN npm run build


FROM caddy:2-alpine AS prod

COPY docker/web/Caddyfile /etc/caddy/Caddyfile
COPY --from=build /app/dist /srv

# Caddy n'a besoin d'aucun droit root : il écoute sur le port 8080 du conteneur,
# et ses fichiers d'état (inutiles ici : pas de certificat, pas d'API d'admin) vont dans /tmp
ENV XDG_CONFIG_HOME=/tmp/caddy/config \
    XDG_DATA_HOME=/tmp/caddy/data
USER 1000:1000
EXPOSE 8080
